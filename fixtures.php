<?php
require_once __DIR__ . '/config/config.php';

$pageTitle = 'Fixtures & scores';
date_default_timezone_set('Africa/Nairobi');
$timezone = new DateTimeZone('Africa/Nairobi');

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$dateObject = DateTime::createFromFormat('!Y-m-d', $selectedDate);

if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}

$matches = [];
$apiError = null;
$usingApi = false;

function goalLiveFixturesRequest(string $url): array
{
    if (!defined('SPORTMONKS_API_TOKEN') || SPORTMONKS_API_TOKEN === '') {
        throw new RuntimeException('Sportmonks API token is not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not enabled in XAMPP.');
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
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Could not connect to Sportmonks: ' . $curlError);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException('Sportmonks returned HTTP ' . $httpStatus . '.');
    }

    $json = json_decode($response, true);
    if (!is_array($json) || !isset($json['data'])) {
        throw new RuntimeException('Sportmonks returned an unexpected response.');
    }
    return $json;
}

function goalLiveTeamName(array $participants, string $side): string
{
    foreach ($participants as $participant) {
        if (strtolower((string) ($participant['meta']['location'] ?? '')) === $side) {
            return $participant['name'] ?? 'Unknown team';
        }
    }
    return 'Unknown team';
}

function goalLiveScore(array $scores, string $side): ?int
{
    foreach ($scores as $score) {
        $description = strtoupper((string) ($score['description'] ?? ''));
        $participant = strtolower((string) ($score['score']['participant'] ?? ''));
        if ($description === 'CURRENT' && $participant === $side) {
            return isset($score['score']['goals']) ? (int) $score['score']['goals'] : null;
        }
    }
    foreach ($scores as $score) {
        $participant = strtolower((string) ($score['score']['participant'] ?? ''));
        if ($participant === $side && isset($score['score']['goals'])) {
            return (int) $score['score']['goals'];
        }
    }
    return null;
}

/**
 * Sportmonks period timing may include the currently ticking period and its minute.
 * Return null if the API has not supplied an active minute.
 */
function goalLiveFixtureMinute(array $periods): ?int
{
    foreach ($periods as $period) {
        if (!empty($period['ticking']) && isset($period['minutes']) && is_numeric($period['minutes'])) {
            return max(0, (int) $period['minutes']);
        }
    }
    return null;
}

function goalLiveFixtureStatusLabel(string $status, ?int $minute): string
{
    $s = strtolower(trim($status));

    if (strpos($s, 'half time') !== false || $s === 'halftime') {
        return 'HT';
    }
    if (strpos($s, 'full time') !== false || $s === 'finished' || $s === 'ft') {
        return 'FT';
    }
    if (strpos($s, 'penalt') !== false) {
        return 'PENALTIES';
    }
    if (strpos($s, 'extra time') !== false || strpos($s, 'extra-time') !== false) {
        return $minute !== null ? 'ET ' . $minute . "'" : 'EXTRA TIME';
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
        return $minute !== null ? 'LIVE ' . $minute . "'" : 'LIVE';
    }
    if (strpos($s, 'not started') !== false || strpos($s, 'scheduled') !== false || strpos($s, 'ns') === 0) {
        return 'NOT STARTED';
    }
    return strtoupper($status);
}

try {
    $apiUrl = 'https://api.sportmonks.com/v3/football/fixtures/date/' .
        rawurlencode($selectedDate) .
        '?include=participants;league;state;scores;periods&per_page=50&page=1';

    $apiResponse = goalLiveFixturesRequest($apiUrl);

    foreach ($apiResponse['data'] as $fixture) {
        $participants = $fixture['participants'] ?? [];
        $scores = $fixture['scores'] ?? [];
        $periods = $fixture['periods'] ?? [];
        $homeTeam = goalLiveTeamName($participants, 'home');
        $awayTeam = goalLiveTeamName($participants, 'away');
        $kickoff = $fixture['starting_at'] ?? null;

        if (!$kickoff || $homeTeam === 'Unknown team' || $awayTeam === 'Unknown team') {
            continue;
        }

        try {
            $kickoffDate = new DateTime($kickoff, new DateTimeZone('UTC'));
            $kickoffDate->setTimezone($timezone);
        } catch (Exception $e) {
            continue;
        }

        $status = (string) ($fixture['state']['name'] ?? 'Scheduled');
        $minute = goalLiveFixtureMinute($periods);

        $matches[] = [
            'id' => null,
            'external_id' => $fixture['id'] ?? null,
            'league' => $fixture['league']['name'] ?? 'Football',
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'kickoff_at' => $kickoffDate->format('Y-m-d H:i:s'),
            'home_score' => goalLiveScore($scores, 'home'),
            'away_score' => goalLiveScore($scores, 'away'),
            'status' => $status,
            'minute' => $minute,
            'status_label' => goalLiveFixtureStatusLabel($status, $minute),
            'source' => 'sportmonks',
        ];
    }

    usort($matches, function ($a, $b) {
        return strcmp($a['kickoff_at'], $b['kickoff_at']);
    });
    $usingApi = true;
} catch (Throwable $e) {
    error_log('GoalLive fixtures API error: ' . $e->getMessage());
    $apiError = 'Live fixture data is temporarily unavailable. Showing published local matches instead.';

    try {
        $statement = db()->prepare(
            'SELECT * FROM matches WHERE is_published = 1 AND DATE(kickoff_at) = ? ORDER BY kickoff_at ASC'
        );
        $statement->execute([$selectedDate]);
        foreach ($statement->fetchAll() as $match) {
            $match['source'] = 'database';
            $match['minute'] = null;
            $match['status_label'] = strtoupper((string) ($match['status'] ?? 'Scheduled'));
            $matches[] = $match;
        }
    } catch (Throwable $databaseError) {
        error_log('GoalLive local fixtures error: ' . $databaseError->getMessage());
        $matches = [];
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="section-heading">
        <div>
            <h1>Fixtures &amp; scores</h1>
            <p class="muted">Times use Africa/Nairobi timezone.</p>
        </div>
    </div>

    <form method="get" action="<?= e(url('fixtures.php')) ?>"
          style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:20px 0;">
        <div>
            <label for="date">Select match date</label><br>
            <input type="date" id="date" name="date" value="<?= e($selectedDate) ?>" required>
        </div>
        <button type="submit">Show fixtures</button>
        <a href="<?= e(url('fixtures.php')) ?>">Today</a>
    </form>

    <?php if ($usingApi): ?>
        <p class="muted">Data source: Sportmonks football API. Live scores refresh every 30 seconds.</p>
    <?php elseif ($apiError): ?>
        <p class="muted"><?= e($apiError) ?></p>
    <?php endif; ?>

    <?php if (!$matches): ?>
        <div class="empty">
            No fixtures found for <?= e(date('M j, Y', strtotime($selectedDate))) ?>.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Competition</th>
                        <th>Match</th>
                        <th>Kick-off (EAT)</th>
                        <th>Score</th>
                        <th>Status / live minute</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $m): ?>
                        <?php
                        $homeScore = $m['home_score'] ?? null;
                        $awayScore = $m['away_score'] ?? null;
                        $hasScore = $homeScore !== null && $awayScore !== null;
                        $statusLabel = (string) ($m['status_label'] ?? strtoupper((string) ($m['status'] ?? 'SCHEDULED')));
                        $isLive = strpos($statusLabel, 'LIVE') === 0 || strpos($statusLabel, 'ET ') === 0;
                        ?>
                        <tr>
                            <td><?= e($m['league'] ?? 'Football') ?></td>
                            <td><?= e($m['home_team']) ?> vs <?= e($m['away_team']) ?></td>
                            <td><?= e((new DateTime($m['kickoff_at'], $timezone))->format('M j, Y H:i')) ?></td>
                            <td><?= $hasScore ? (int) $homeScore . ' - ' . (int) $awayScore : '–' ?></td>
                            <td>
                                <span
                                    <?= $isLive ? 'class="goallive-live-status"' : '' ?>
                                    <?php if ($isLive && isset($m['minute'])): ?>
                                        data-live-minute="<?= (int) $m['minute'] ?>"
                                    <?php endif; ?>
                                ><?= e($statusLabel) ?></span>
                            </td>
                            <td>
                                <?php if (($m['source'] ?? '') === 'database'): ?>
                                    <a href="<?= e(url('watch.php?id=' . (int) $m['id'])) ?>">Details</a>
                                <?php elseif (!empty($m['external_id']) && (int) $m['external_id'] > 0): ?>
                                    <a href="<?= e(url('watch.php?fixture=' . (int) $m['external_id'])) ?>">Match centre</a>
                                <?php else: ?>
                                    <span class="muted">Details unavailable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($usingApi): ?>
<script>
(function () {
    // Advance the displayed live minute locally between API refreshes.
    // The authoritative minute is reloaded from Sportmonks every 30 seconds.
    const liveStatuses = document.querySelectorAll('[data-live-minute]');
    liveStatuses.forEach(function (element) {
        let minute = Number(element.getAttribute('data-live-minute'));
        if (!Number.isFinite(minute)) return;

        let seconds = 0;
        window.setInterval(function () {
            seconds += 1;
            if (seconds >= 60) {
                seconds = 0;
                minute += 1;
                element.setAttribute('data-live-minute', String(minute));
                const current = element.textContent.trim();
                if (current.startsWith('LIVE ')) {
                    element.textContent = 'LIVE ' + minute + "'";
                } else if (current.startsWith('ET ')) {
                    element.textContent = 'ET ' + minute + "'";
                }
            }
        }, 1000);
    });

    window.setTimeout(function () {
        window.location.reload();
    }, 30000);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
