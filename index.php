
<?php
require_once __DIR__ . '/config/config.php';

$pageTitle = 'Live football scores, fixtures and match centre';

// Leave empty until you have an authorized stream URL.
$homeStreamUrl = '';

$matches = [];
$liveCount = 0;
$upcomingCount = 0;
$leagueCount = 0;
$dataSource = 'database';
$apiError = false;

/**
 * Validate an HTTP/HTTPS stream URL.
 */
function goalliveValidStreamUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true)
        && !empty(parse_url($url, PHP_URL_HOST));
}

/**
 * Request football data from Sportmonks.
 * The API token stays on the PHP server.
 */
function goalliveSportmonksGet(string $url): ?array
{
    if (
        !defined('SPORTMONKS_API_TOKEN') ||
        trim((string) SPORTMONKS_API_TOKEN) === '' ||
        !function_exists('curl_init')
    ) {
        return null;
    }

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . SPORTMONKS_API_TOKEN,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded) || !isset($decoded['data'])) {
        return null;
    }

    return $decoded;
}

/**
 * Convert a Sportmonks fixture into GoalLive fields.
 */
function goalliveMapFixture(array $fixture): ?array
{
    $participants = $fixture['participants'] ?? [];

    $homeTeam = '';
    $awayTeam = '';

    foreach ($participants as $participant) {
        $location = strtolower(
            (string) ($participant['meta']['location'] ?? '')
        );

        if ($location === 'home') {
            $homeTeam = (string) ($participant['name'] ?? '');
        } elseif ($location === 'away') {
            $awayTeam = (string) ($participant['name'] ?? '');
        }
    }

    if ($homeTeam === '' || $awayTeam === '') {
        return null;
    }

    $state = $fixture['state'] ?? [];
    $stateName = strtoupper((string) (
        $state['state'] ?? $state['short_name'] ?? $state['name'] ?? ''
    ));

    $status = match ($stateName) {
        'LIVE', 'INPLAY_1ST_HALF', 'INPLAY_2ND_HALF',
        'INPLAY_ET', 'PEN_LIVE', '1H', '2H' => 'live',

        'HT', 'HALF_TIME', 'HALFTIME' => 'halftime',

        'FT', 'AET', 'FT_PEN', 'FULL_TIME',
        'FINISHED' => 'finished',

        'POSTP', 'POSTPONED', 'DELAYED' => 'postponed',

        'CANC', 'CANCELLED', 'CANCELED' => 'cancelled',

        default => 'upcoming',
    };

    $homeScore = null;
    $awayScore = null;

    foreach (($fixture['scores'] ?? []) as $scoreItem) {
        $description = strtoupper((string) (
            $scoreItem['description'] ?? ''
        ));

        $participant = strtolower((string) (
            $scoreItem['score']['participant'] ?? ''
        ));

        $goals = $scoreItem['score']['goals'] ?? null;

        if ($description === 'CURRENT' && is_numeric($goals)) {
            if ($participant === 'home') {
                $homeScore = (int) $goals;
            } elseif ($participant === 'away') {
                $awayScore = (int) $goals;
            }
        }
    }

    return [
        'id' => (int) ($fixture['id'] ?? 0),
        'home_team' => $homeTeam,
        'away_team' => $awayTeam,
        'league' => (string) (
            $fixture['league']['name'] ?? 'Football'
        ),
        'kickoff_at' => (string) ($fixture['starting_at'] ?? ''),
        'home_score' => $homeScore,
        'away_score' => $awayScore,
        'status' => $status,
        'source' => 'sportmonks',
    ];
}

try {
    /*
     * FIRST: Fetch today's and tomorrow's fixtures.
     */
    if (
        defined('SPORTMONKS_API_TOKEN') &&
        trim((string) SPORTMONKS_API_TOKEN) !== '' &&
        function_exists('curl_init')
    ) {
        $today = new DateTimeImmutable('today');

        $dates = [
            $today->format('Y-m-d'),
            $today->modify('+1 day')->format('Y-m-d'),
        ];

        $apiMatches = [];
        $apiRequestSucceeded = false;

        foreach ($dates as $date) {
            $url = 'https://api.sportmonks.com/v3/football/fixtures/date/'
                . $date
                . '?include=participants;league;state;scores'
                . '&per_page=50&page=1';

            $result = goalliveSportmonksGet($url);

            if ($result !== null) {
                $apiRequestSucceeded = true;

                foreach ($result['data'] as $fixture) {
                    if (!is_array($fixture)) {
                        continue;
                    }

                    $mapped = goalliveMapFixture($fixture);

                    if ($mapped !== null) {
                        $apiMatches[] = $mapped;
                    }
                }
            }
        }

        if ($apiRequestSucceeded) {
            $uniqueMatches = [];

            foreach ($apiMatches as $match) {
                $uniqueMatches[$match['id']] = $match;
            }

            $apiMatches = array_values($uniqueMatches);

            usort($apiMatches, static function ($a, $b) {
                $priority = [
                    'live' => 0,
                    'halftime' => 1,
                    'upcoming' => 2,
                    'postponed' => 3,
                    'cancelled' => 4,
                    'finished' => 5,
                ];

                $priorityA = $priority[$a['status']] ?? 6;
                $priorityB = $priority[$b['status']] ?? 6;

                if ($priorityA !== $priorityB) {
                    return $priorityA <=> $priorityB;
                }

                return strcmp($a['kickoff_at'], $b['kickoff_at']);
            });

            $matches = array_slice($apiMatches, 0, 9);
            $dataSource = 'sportmonks';

            $leagueNames = [];

            foreach ($apiMatches as $match) {
                $leagueNames[$match['league']] = true;

                if (in_array(
                    $match['status'],
                    ['live', 'halftime'],
                    true
                )) {
                    $liveCount++;
                }

                if ($match['status'] === 'upcoming') {
                    $upcomingCount++;
                }
            }

            $leagueCount = count($leagueNames);
        }
    }

    /*
     * FALLBACK: Use published database matches if the API
     * is unavailable or the API token is not configured.
     */
    if ($dataSource === 'database') {
        $pdo = db();

        $stmt = $pdo->query(
            "SELECT id, home_team, away_team, league, kickoff_at,
                    home_score, away_score, status
             FROM matches
             WHERE is_published = 1
             ORDER BY
               CASE status
                 WHEN 'live' THEN 0
                 WHEN 'halftime' THEN 1
                 WHEN 'upcoming' THEN 2
                 ELSE 3
               END,
               kickoff_at ASC
             LIMIT 9"
        );

        $matches = $stmt->fetchAll();

        $stats = $pdo->query(
            "SELECT
                SUM(CASE WHEN status IN ('live','halftime')
                    THEN 1 ELSE 0 END) AS live_count,
                SUM(CASE WHEN status = 'upcoming'
                    THEN 1 ELSE 0 END) AS upcoming_count,
                COUNT(DISTINCT league) AS league_count
             FROM matches
             WHERE is_published = 1"
        )->fetch();

        $liveCount = (int) ($stats['live_count'] ?? 0);
        $upcomingCount = (int) ($stats['upcoming_count'] ?? 0);
        $leagueCount = (int) ($stats['league_count'] ?? 0);

        $apiError = true;
    }
} catch (Throwable $e) {
    // Do not expose server errors or API credentials to visitors.
    if ($dataSource === 'database') {
        $matches = [];
        $liveCount = 0;
        $upcomingCount = 0;
        $leagueCount = 0;
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="hero hero-modern">
  <div class="hero-copy">
    <div class="eyebrow"><span class="eyebrow-dot"></span> YOUR FOOTBALL MATCH CENTRE</div>
    <h1>Feel every<br><em>moment.</em></h1>
    <p class="hero-description">Live scores, upcoming fixtures and your match-day destination — all in one place.</p>

    <div class="hero-actions">
      <a class="btn" href="<?= e(url('fixtures.php')) ?>">Explore fixtures <span aria-hidden="true">↗</span></a>
      <a class="text-link" href="#featured-matches">See featured matches <span aria-hidden="true">↓</span></a>
    </div>

    <div class="hero-trust"><span class="trust-icon">✓</span> Match information in one clear view</div>
  </div>

  <div class="hero-visual">
    <div class="stadium-glow"></div>

    <div class="hero-visual-top">
      <span class="live-pill"><span></span> MATCH CENTRE</span>
      <span class="visual-date"><?= e(date('D, M j')) ?></span>
    </div>

    <div class="pitch-mark pitch-mark-one"></div>
    <div class="pitch-mark pitch-mark-two"></div>
    <div class="hero-ball" aria-hidden="true">⚽</div>

    <div class="hero-visual-bottom">
      <div>
        <span class="visual-label">ALL THE ACTION</span>
        <strong>One home for football.</strong>
      </div>
      <span class="visual-arrow">↗</span>
    </div>
  </div>
</section>

<section class="stats-strip" aria-label="Match statistics">
  <div class="stat-item">
    <span class="stat-icon stat-live">●</span>
    <div><strong><?= $liveCount ?></strong><span>Live / halftime</span></div>
  </div>

  <div class="stat-item">
    <span class="stat-icon">◷</span>
    <div><strong><?= $upcomingCount ?></strong><span>Upcoming fixtures</span></div>
  </div>

  <div class="stat-item">
    <span class="stat-icon">▤</span>
    <div><strong><?= $leagueCount ?></strong><span>Competitions</span></div>
  </div>

  <div class="stat-note">
    <span class="status-indicator"></span>
    <?= $dataSource === 'sportmonks'
        ? 'Fixture data from Sportmonks'
        : 'Showing database match data' ?>
  </div>
</section>

<section class="section featured-section" id="featured-matches">
  <div class="section-heading">
    <div>
      <p class="section-kicker">DON'T MISS A MOMENT</p>
      <h2>Featured matches</h2>
      <p class="muted">
        <?= $dataSource === 'sportmonks'
            ? 'Fixtures supplied by the football data API.'
            : 'The latest fixtures available on GoalLive.' ?>
      </p>
    </div>

    <a class="btn btn-secondary" href="<?= e(url('fixtures.php')) ?>">View all fixtures <span aria-hidden="true">→</span></a>
  </div>

  <?php if (!$matches): ?>
    <div class="empty">
      <span class="empty-icon">⚽</span>
      <h3>No fixtures available</h3>
      <p>No fixtures were returned for today or tomorrow, and no database matches were available as a fallback.</p>

      <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
        <a class="btn" href="<?= e(url('admin/index.php')) ?>">Open admin dashboard</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="grid match-grid">
      <?php foreach ($matches as $m):
        $isLive = in_array($m['status'], ['live', 'halftime'], true);
        $kickoff = !empty($m['kickoff_at'])
            ? strtotime($m['kickoff_at'])
            : false;

        $statusLabels = [
            'live' => 'LIVE',
            'halftime' => 'HALF TIME',
            'upcoming' => 'UPCOMING',
            'finished' => 'FULL TIME',
            'postponed' => 'POSTPONED',
            'cancelled' => 'CANCELLED',
        ];

        $statusLabel = $statusLabels[$m['status']]
            ?? strtoupper((string) $m['status']);

        $homeTeam = (string) $m['home_team'];
        $awayTeam = (string) $m['away_team'];
        $isApiMatch = ($m['source'] ?? '') === 'sportmonks';
      ?>
      <article class="match-card match-card-modern">
        <div class="match-meta">
          <span class="league-label"><span class="league-mark">◆</span><?= e($m['league']) ?></span>

          <span class="match-status <?= $isLive ? 'live' : 'status-' . e($m['status']) ?>">
            <?php if ($isLive): ?><span class="pulse-dot"></span><?php endif; ?>
            <?= e($statusLabel) ?>
          </span>
        </div>

        <div class="teams teams-modern">
          <div class="team">
            <span class="team-crest crest-home"><?= e(strtoupper(substr($homeTeam, 0, 1))) ?></span>
            <span class="team-name"><?= e($homeTeam) ?></span>
          </div>

          <div class="score-wrap">
            <div class="score">
              <?= $m['home_score'] === null ? '–' : (int) $m['home_score'] ?>
              <span>:</span>
              <?= $m['away_score'] === null ? '–' : (int) $m['away_score'] ?>
            </div>

            <span class="score-caption">
              <?= $isLive
                  ? 'CURRENT SCORE'
                  : ($m['status'] === 'finished' ? 'FULL TIME' : 'VS') ?>
            </span>
          </div>

          <div class="team">
            <span class="team-crest crest-away"><?= e(strtoupper(substr($awayTeam, 0, 1))) ?></span>
            <span class="team-name"><?= e($awayTeam) ?></span>
          </div>
        </div>

        <div class="match-foot match-foot-modern">
          <span class="kickoff">
            <span aria-hidden="true">◷</span>
            <?= $kickoff ? e(date('M j · H:i', $kickoff)) : 'Time TBC' ?>
          </span>

          <?php if ($isApiMatch): ?>
            <a href="<?= e(url('fixtures.php')) ?>">View fixtures <span aria-hidden="true">→</span></a>
          <?php else: ?>
            <a href="<?= e(url('watch.php?id=' . (int) $m['id'])) ?>">Match centre <span aria-hidden="true">→</span></a>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- LIVE STREAM SECTION -->
<section class="section home-stream-section" id="live-stream">
  <div class="section-heading">
    <div>
      <p class="section-kicker">WATCH ON GOALLIVE</p>
      <h2>Live Stream</h2>
      <p class="muted">
        Watch broadcasts you are authorized to embed or distribute.
      </p>
    </div>
  </div>

  <div style="background:#101820;border-radius:16px;padding:16px;color:#fff;">
    <?php if (goalliveValidStreamUrl($homeStreamUrl)): ?>
      <video
        id="goallive-home-video"
        controls
        playsinline
        preload="metadata"
        style="display:block;width:100%;max-height:520px;background:#000;border-radius:10px;"
      >
        Your browser does not support HTML video.
      </video>

      <p id="goallive-stream-message" role="status" style="margin:12px 0 0;color:#ddd;">
        Loading stream player…
      </p>
    <?php else: ?>
      <div style="min-height:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:20px;">
        <div style="font-size:48px;" aria-hidden="true">▶</div>
        <h3 style="color:#fff;margin:12px 0;">Your match-day stream</h3>
        <p style="color:#cbd5e1;max-width:480px;margin:0;">
          No stream has been configured yet. Once you select a provider
          and obtain an authorized stream URL, playback can be enabled here.
        </p>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if (goalliveValidStreamUrl($homeStreamUrl)): ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>
<script>
(() => {
    const video = document.getElementById('goallive-home-video');
    const message = document.getElementById('goallive-stream-message');

    if (!video || !message) return;

    const source = <?= json_encode(
        $homeStreamUrl,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;

    const isHls = /\.m3u8(?:$|[?#])/i.test(source);

    const showError = () => {
        message.textContent =
            'Playback failed. Check the authorized stream URL, provider settings, and stream availability.';
    };

    if (isHls && video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = source;
    } else if (isHls && window.Hls && window.Hls.isSupported()) {
        const hls = new Hls();

        hls.loadSource(source);
        hls.attachMedia(video);

        hls.on(Hls.Events.ERROR, (event, data) => {
            if (data.fatal) {
                showError();
                hls.destroy();
            }
        });
    } else if (isHls) {
        message.textContent =
            'This browser cannot play this HLS stream. Try a supported browser or the provider’s official player.';
        return;
    } else {
        video.src = source;
    }

    video.addEventListener('playing', () => {
        message.textContent = 'Stream playing.';
    });

    video.addEventListener('error', showError);
})();
</script>
<?php endif; ?>

<section class="bottom-promo">
  <div class="promo-mark">GL<span>+</span></div>

  <div class="promo-copy">
    <p class="section-kicker">BUILT FOR MATCH DAY</p>
    <h2>Keep your football close.</h2>
    <p class="muted">Check schedules, follow scores and open the match centre for details.</p>
  </div>

  <a class="btn btn-light" href="<?= e(url('fixtures.php')) ?>">Browse matches <span aria-hidden="true">↗</span></a>
</section>

<section class="responsible-note">
  <span class="note-icon">ⓘ</span>
  <p><strong>Streaming note</strong> Video playback is available only when an authorized stream is configured. Football data and broadcast rights are separate services.</p>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script>
(() => {
    // Refresh server-side fixture data every 60 seconds.
    const refreshInterval = 60000;

    window.setInterval(() => {
        if (!document.hidden) {
            window.location.reload();
        }
    }, refreshInterval);
})();
</script>
