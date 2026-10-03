<?php
// Shared page frame (head, header with event info, footer) for public pages.

declare(strict_types=1);

function render_header(string $title, ReservationService $service): void
{
    $s = $service->settings();
    $assetVersion = (string) filemtime(ROOT_DIR . '/public/assets/app.css');
    ?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($title) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/img/icons/organizer.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Calistoga&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=<?= h($assetVersion) ?>">
</head>
<body>
<header class="hero">
    <img class="hero-figure hero-figure--left" src="assets/img/boy.svg" alt="">
    <div class="hero-text">
        <h1><span class="hero-town">Šumperský</span> Skautský ples</h1>
        <?php if (!empty($s['event_intro'])): ?>
            <p class="intro"><?= h($s['event_intro']) ?></p>
        <?php endif; ?>
        <ul class="event-facts">
            <li><img src="assets/img/icons/where.svg" alt="">
                <span class="visually-hidden">Kde:</span>
                <?php if (!empty($s['venue_url'])): ?>
                    <a href="<?= h($s['venue_url']) ?>" target="_blank" rel="noopener"><?= h($s['venue'] ?? '') ?></a>
                <?php else: ?>
                    <?= h($s['venue'] ?? '') ?>
                <?php endif; ?>
            </li>
            <li><img src="assets/img/icons/when.svg" alt=""><span class="visually-hidden">Kdy:</span> <?= h($s['event_date'] ?? '') ?></li>
            <li><img src="assets/img/icons/band.svg" alt="">
                <span class="visually-hidden">Kapela:</span>
                <?php if (!empty($s['band_url'])): ?>
                    <a href="<?= h($s['band_url']) ?>" target="_blank" rel="noopener"><?= h($s['band'] ?? '') ?></a>
                <?php else: ?>
                    <?= h($s['band'] ?? '') ?>
                <?php endif; ?>
            </li>
            <li><img src="assets/img/icons/organizer.svg" alt=""><span class="visually-hidden">Organizátor:</span> <?= h($s['organizer'] ?? '') ?></li>
        </ul>
    </div>
    <img class="hero-figure hero-figure--right" src="assets/img/girl.svg" alt="">
</header>
<main>
    <?php
}

function render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <p>Junák – český skaut, Šumperk</p>
</footer>
</body>
</html>
    <?php
}
