
<?php
require_once __DIR__ . '/config/config.php';
$pageTitle = 'Match centre';

date_default_timezone_set('Africa/Nairobi');
$timezone = new DateTimeZone('Africa/Nairobi');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$fixtureId = filter_input(INPUT_GET, 'fixture', FILTER_VALIDATE_INT);

$match = null;
$isApiMatch = false;
$matchMinute = null;
$clockLabel = null;
$apiError = null;

/**
 * Request a fixture securely from Sportmonks.
 */
function goalLiveWatchRequest(string $url): array
{
    if (!defined('SPORTMONKS_API_TOKEN') ||
        SPORTMONKS_API_TOKEN === '') {
        throw new RuntimeException('Sportmonks token is not configured.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled.');
    }

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . SPORTMONKS_API_TOKEN,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('API connection failed: ' . $error);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Sportmonks HTTP ' . $httpCode);
    }

    $json = json_decode($response, true);

    if (!is_array($json) || !isset($json['data'])) {
        throw new RuntimeException('Unexpected Sportmonks response.');
    }

    return $json;
}

function goalLiveWatchTeam(array $participants, string $side): string
{
    foreach ($participants as $participant) {
        $location = strtolower(
            (string) ($participant['meta']['location'] ?? '')
        );

        if ($location === $side) {
            return $participant['name'] ?? 'Unknown team';
        }
    }

    return 'Unknown team';
}

function goalLiveWatchScore(array $scores, string $side): ?int
{
    // Prefer the current score rather than half-time or other score types.
    foreach ($scores as $score) {
        $description = strtoupper(
            (string) ($score['description'] ?? '')
        );

        $participant = strtolower(
            (string) ($score['score']['participant'] ?? '')
        );

        if ($description === 'CURRENT' && $participant === $side) {
            return isset($score['score']['goals'])
                ? (int) $score['score']['goals']
                : null;
        }
    }

    foreach ($scores as $score) {
        $participant = strtolower(
            (string) ($score['score']['participant'] ?? '')
        );

        if ($participant === $side &&
            isset($score['score']['goals'])) {
            return (int) $score['score']['goals'];
        }
    }

    return null;
}

/**
 * Use elapsed timing only when Sportmonks supplies an active period.
 * Do not start an invented timer from the scheduled kick-off.
 */
function goalLiveWatchMinute(array $periods): ?int
{
    foreach ($periods as $period) {
        $isTicking = $period['ticking'] ?? false;

        if (
            $isTicking &&
            isset($period['minutes']) &&
            is_numeric($period['minutes'])
        ) {
            return max(0, (int) $period['minutes']);
        }
    }

    return null;
}

function goalLiveWatchClockLabel(
    string $status,
    ?int $minute
): string {
    $s = strtolower($status);

    if (
        strpos($s, 'half time') !== false ||
        $s === 'halftime'
    ) {
        return 'HT';
    }

    if (
        strpos($s, 'full time') !== false ||
        $s === 'finished' ||
        $s === 'ft'
    ) {
        return 'FT';
    }

    if (
        strpos($s, 'extra time') !== false ||
        strpos($s, 'extra-time') !== false
    ) {
        return $minute !== null
            ? 'ET ' . $minute . "'"
            : 'EXTRA TIME';
    }

    if (
        strpos($s, 'penalty') !== false
    ) {
        return 'PENALTIES';
    }

    if (
        strpos($s, 'live') !== false ||
        strpos($s, 'inplay') !== false ||
        strpos($s, 'in play') !== false ||
        strpos($s, '1st half') !== false ||
        strpos($s, '2nd half') !== false ||
        strpos($s, 'first half') !== false ||
        strpos($s, 'second half') !== false
    ) {
        return $minute !== null
            ? 'LIVE ' . $minute . "'"
            : 'LIVE';
    }

    if (
        strpos($s, 'not started') !== false ||
        strpos($s, 'scheduled') !== false ||
        strpos($s, 'NS') === 0
    ) {
        return 'NOT STARTED';
    }

    return strtoupper($status);
}

if ($fixtureId && $fixtureId > 0) {
    // Real Sportmonks fixture.
    try {
        $url = 'https://api.sportmonks.com/v3/football/fixtures/' .
            $fixtureId .
            '?include=participants;league;state;scores;periods';

        $response = goalLiveWatchRequest($url);
        $fixture = $response['data'];

        $participants = $fixture['participants'] ?? [];
        $scores = $fixture['scores'] ?? [];
        $periods = $fixture['periods'] ?? [];

        $homeTeam = goalLiveWatchTeam($participants, 'home');
        $awayTeam = goalLiveWatchTeam($participants, 'away');

        if (
            $homeTeam === 'Unknown team' ||
            $awayTeam === 'Unknown team'
        ) {
            throw new RuntimeException(
                'The fixture did not include both teams.'
            );
        }

        $kickoff = $fixture['starting_at'] ?? null;

        if (!$kickoff) {
            throw new RuntimeException(
                'The fixture has no kick-off timestamp.'
            );
        }

        $kickoffDate = new DateTime($kickoff, new DateTimeZone('UTC'));
        $kickoffDate->setTimezone($timezone);

        $status = $fixture['state']['name'] ?? 'Scheduled';

        $match = [
            'id' => null,
            'external_id' => $fixture['id'] ?? $fixtureId,
            'league' => $fixture['league']['name'] ?? 'Football',
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'kickoff_at' => $kickoffDate->format('Y-m-d H:i:s'),
            'home_score' => goalLiveWatchScore($scores, 'home'),
            'away_score' => goalLiveWatchScore($scores, 'away'),
            'status' => $status,
            'stream_url' => null,
        ];

        $matchMinute = goalLiveWatchMinute($periods);
        $clockLabel = goalLiveWatchClockLabel($status, $matchMinute);
        $isApiMatch = true;

    } catch (Throwable $e) {
        error_log('GoalLive match-centre API error: ' . $e->getMessage());
        $apiError = 'Live match information is temporarily unavailable.';
    }

} elseif ($id && $id > 0) {
    // Existing MySQL match.
    $stmt = db()->prepare(
        'SELECT * FROM matches
         WHERE id = ? AND is_published = 1
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $match = $stmt->fetch();

    if ($match) {
        $clockLabel = ucfirst((string) $match['status']);
    }
}

if (!$match) {
    http_response_code(404);
    $pageTitle = 'Match not found';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="empty section">
        <h1>Match not found</h1>
        <p><?= e($apiError ?? 'This match could not be found.') ?></p>
        <p><a href="<?= e(url('fixtures.php')) ?>">Back to fixtures</a></p>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $match['home_team'] . ' vs ' . $match['away_team'];

require __DIR__ . '/includes/header.php';
?>

<section class="section">
    <p class="eyebrow">
        <?= e($match['league']) ?> · <?= e(strtoupper($match['status'])) ?>
    </p>

    <h1>
        <?= e($match['home_team']) ?>
        vs
        <?= e($match['away_team']) ?>
    </h1>

    <p class="muted">
        <?= e(
            (new DateTime(
                $match['kickoff_at'],
                $timezone
            ))->format('l, M j, Y H:i')
        ) ?>
        · East Africa Time
    </p>

    <div class="player-grid">
        <div class="panel">
            <div class="big-score" style="display:flex;align-items:center;justify-content:center;gap:1rem;flex-wrap:wrap">
                <span>
                    <?= ($match['home_score'] ?? null) === null
                        ? '–'
                        : (int) $match['home_score'] ?>
                    :
                    <?= ($match['away_score'] ?? null) === null
                        ? '–'
                        : (int) $match['away_score'] ?>
                </span>
                <span style="font-size:0.9rem;font-weight:bold;white-space:nowrap">
                    <?= e($clockLabel ?? ucfirst((string) $match['status'])) ?>
                </span>
            </div>

            <?php
            // Only allow web URLs for streams configured by the site operator.
            $streamUrl = trim((string) ($match['stream_url'] ?? ''));
            $streamScheme = strtolower((string) parse_url($streamUrl, PHP_URL_SCHEME));
            $hasPlayableStream = !$isApiMatch &&
                $streamUrl !== '' &&
                in_array($streamScheme, ['http', 'https'], true);
            $isHlsStream = $hasPlayableStream &&
                (bool) preg_match('/\\.m3u8(?:$|[?#])/i', $streamUrl);
            ?>

            <?php if ($hasPlayableStream): ?>
                <div class="video-wrap">
                    <video
                        id="goallive-stream-player"
                        controls
                        playsinline
                        preload="metadata"
                        style="width:100%;height:100%;background:#000"
                        <?php if (!$isHlsStream): ?>
                            src="<?= e($streamUrl) ?>"
                        <?php endif; ?>
                    >
                        Your browser does not support HTML5 video.
                    </video>
                </div>
                <p id="goallive-stream-message" class="muted small" aria-live="polite">
                    Loading authorized stream…
                </p>
                <?php if ($isHlsStream): ?>
                    <script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>
                    <script>
                    (function () {
                        const video = document.getElementById('goallive-stream-player');
                        const message = document.getElementById('goallive-stream-message');
                        const streamUrl = <?= json_encode(
                            $streamUrl,
                            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                        ) ?>;

                        if (video.canPlayType('application/vnd.apple.mpegurl')) {
                            video.src = streamUrl;
                            message.textContent = 'Stream ready. Press Play to start.';
                        } else if (window.Hls && window.Hls.isSupported()) {
                            const hls = new Hls();
                            hls.loadSource(streamUrl);
                            hls.attachMedia(video);
                            hls.on(Hls.Events.MANIFEST_PARSED, function () {
                                message.textContent = 'Stream ready. Press Play to start.';
                            });
                            hls.on(Hls.Events.ERROR, function (event, data) {
                                if (data && data.fatal) {
                                    message.textContent =
                                        'Unable to play this stream. Check the provider URL, access permissions, and CORS settings.';
                                    hls.destroy();
                                }
                            });
                        } else {
                            message.textContent =
                                'This browser does not support HLS playback. Try a supported browser or provider embed player.';
                        }

                        video.addEventListener('error', function () {
                            message.textContent =
                                'The stream could not be loaded. It may be offline, the URL may be incorrect, or the provider may block playback from this site.';
                        });
                    })();
                    </script>
                <?php else: ?>
                    <p class="muted small">
                        If playback fails, check that the provider permits embedding and that the URL points directly to a compatible video stream.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty">
                    <h3>Live stream not connected yet</h3>
                    <p>
                        GoalLive is ready to display an authorized stream once you select a provider
                        and configure its permitted stream URL for this match.
                    </p>
                    <p class="muted small">
                        Sportmonks supplies fixture and score data, not broadcast video.
                        Do not add unofficial or unauthorized broadcast links.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <aside class="panel">
            <h2>Match information</h2>

            <p>
                <strong>Competition:</strong>
                <?= e($match['league']) ?>
            </p>

            <p>
                <strong>Status:</strong>
                <?= e(ucfirst($match['status'])) ?>
            </p>

            <?php if ($isApiMatch): ?>
                <p>
                    <strong>Match clock:</strong>
                    <?= e($clockLabel ?? 'Waiting for timing data') ?>
                </p>

                <p class="muted small">
                    Score and status refresh automatically.
                    The displayed minute depends on timing data available
                    from Sportmonks.
                </p>
            <?php endif; ?>

            <p class="muted small">
                GoalLive only displays streams the site operator has
                permission to embed or distribute.
            </p>
        </aside>
    </div>
</section>

<?php if ($isApiMatch): ?>
<script>
(function () {
    // Refresh API match data every 30 seconds.
    window.setTimeout(function () {
        window.location.reload();
    }, 30000);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
