<?php

/**
 * Static demo of the reservation page for a customer (dev\demo.cmd): renders the real pages from the local
 * database into plain HTML files that work on any static web (no PHP, no database).
 *
 *   ..\dev\php.cmd bin/export-demo.php <output dir>
 *
 * Writes index.html (the reservation page; the seat picker uses the in-browser demo API, front-demo.tsx),
 * moje-rezervace.html (the page shown after confirming, with a sample reservation), plan-salu.svg and
 * robots.txt. The assets (build/, fonts/, img/) are copied by dev/demo.ps1. Everything the export needs in
 * the database (the sale stage, the sample reservation, a made-up bank account) happens in one transaction
 * that is rolled back at the end, so the local data stay as they were.
 */

declare(strict_types=1);

use App\Bootstrap;
use App\Model\Hall\HallSync;
use App\Model\Reservation\ReservationService;
use App\Model\Settings\Settings;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Utils\Json;

require __DIR__ . '/../vendor/autoload.php';

/** Share of the seats shown as sold to other people. */
const TakenShare = 0.33;
/** Made-up account for the payment QR code of the sample reservation (nobody must pay to a real one). */
const DemoAccount = '1234567890/2010';
const Banner = 'Ukázka – nejde o skutečný prodej, nic se neodesílá ani neukládá.';

$out = $argv[1] ?? null;
if ($out === null) {
	fwrite(STDERR, "Usage: php bin/export-demo.php <output dir>\n");
	exit(1);
}
if (!is_dir($out) && !mkdir($out, 0777, true)) {
	fwrite(STDERR, "Cannot create $out\n");
	exit(1);
}

// Production mode (no Tracy bar in the pages), a request as if the site ran at the root of some host.
putenv('APP_ENV=production');
$_SERVER += ['HTTP_HOST' => 'demo.local', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'REQUEST_METHOD' => 'GET'];
$container = Bootstrap::boot(tracy: false)->createContainer();
$db = $container->getByType(PDO::class);
$plan = $container->getByType(HallSync::class)->plan();
if ($plan === null) {
	fwrite(STDERR, "No hall plan in the local database (Nebezpečná zóna -> Plánek sálu, or bin/import-hall.php).\n");
	exit(1);
}

$db->beginTransaction();
try {
	$db->prepare("REPLACE INTO settings (name, value) VALUES ('site_mode', 'public'), ('bank_account', ?)")->execute([DemoAccount]);
	$settings = $container->getByType(Settings::class);
	$layout = $container->getByType(ReservationService::class)->hallLayout();
	$seatIds = array_map(static fn(array $s): int => (int) $s['id'], $layout['seats']);

	// Seats "sold to other people": the same every time (fixed seed), about a third of the hall.
	mt_srand(2027);
	$taken = array_values(array_filter($seatIds, static fn(): bool => mt_rand() / mt_getrandmax() < TakenShare));
	$free = array_values(array_diff($seatIds, $taken));

	$demo = [
		'taken' => $taken,
		'maxTickets' => $settings->int('max_ticket', 10),
		'standingLeft' => $settings->int('standing_capacity'),
		'holdSeconds' => $settings->int('hold_seconds', 300),
		'prices' => ['seat' => $settings->int('price_seat'), 'standing' => $settings->int('price_standing')],
		'redirect' => 'moje-rezervace.html',
	];

	$home = render($container, 'Front:Home', []);
	$home = preg_replace_callback(
		'~<script type="application/json" id="seat-picker-data">(.*?)</script>~s',
		static function (array $m) use ($demo): string {
			$data = Json::decode($m[1], forceArrays: true); // htmlSafe JSON: no HTML entities inside
			$data['api'] = '';
			$data['csrf'] = '';
			$data['layout']['svg'] = 'plan-salu.svg';
			return '<script type="application/json" id="seat-picker-data">' . Json::encode($data, htmlSafe: true) . "</script>\n"
				. '<script type="application/json" id="seat-picker-demo">' . Json::encode($demo, htmlSafe: true) . '</script>';
		},
		$home,
		1,
		$count,
	);
	if ($count !== 1) {
		throw new RuntimeException('The seat picker data were not found in the reservation page.');
	}
	write($out, 'index.html', $home);

	// The sample reservation behind "Moje rezervace": two free seats and one standing ticket, not paid.
	$seats = array_slice($free, intdiv(count($free), 2), 2);
	$token = bin2hex(random_bytes(16));
	$total = count($seats) * $settings->int('price_seat') + $settings->int('price_standing');
	$db->prepare(
		"INSERT INTO reservations (email, name, standing_tickets, status, total_price, access_token, confirmed_at, email_sent_at)
		VALUES ('jana@example.com', 'Jana Ukázková', 1, 'confirmed', ?, ?, NOW(), NOW())",
	)->execute([$total, $token]);
	$id = (int) $db->lastInsertId();
	$stmt = $db->prepare("INSERT INTO seat_bookings (seat_id, reservation_id, state) VALUES (?, ?, 'reserved')");
	foreach ($seats as $seat) {
		$stmt->execute([$seat, $id]);
	}
	write($out, 'moje-rezervace.html', render($container, 'Front:Reservation', ['id' => $id, 'token' => $token, 'new' => true]));

	write($out, 'plan-salu.svg', $plan['svg']);
	write($out, 'robots.txt', "User-agent: *\nDisallow: /\n");
} finally {
	$db->rollBack();
}
echo "Demo exported to $out (taken seats: " . count($taken) . ' of ' . count($seatIds) . ")\n";


/**
 * The page of a presenter as a static file: relative links, the demo script, a banner, noindex.
 * @param array<string, mixed> $params
 */
function render(Nette\DI\Container $container, string $presenterName, array $params): string
{
	$presenter = $container->getByType(IPresenterFactory::class)->createPresenter($presenterName);
	if ($presenter instanceof Presenter) {
		$presenter->autoCanonicalize = false;
	}
	$response = $presenter->run(new Request($presenterName, 'GET', $params + ['action' => 'default']));
	if (!$response instanceof TextResponse) {
		throw new RuntimeException("$presenterName did not render a page.");
	}
	$html = (string) $response->getSource();

	// The real front.js talks to the server: the demo bundle replaces it.
	$html = (string) preg_replace('~src="/build/front\.js(\?[^"]*)?"~', 'src="build/front-demo.js"', $html);
	// Links to the home page, then every other root-relative URL becomes relative (any folder of any host).
	$html = (string) preg_replace('~href="/"~', 'href="index.html"', $html);
	$html = (string) preg_replace('~(href|src)="/(?!/)~', '$1="', $html);
	// Any URL of any host (e.g. https://example.com/ples-test/): a folder opened without the trailing slash
	// (/ples-test) would resolve the relative links against the parent folder, so add the slash first.
	$html = str_replace('<head>', "<head>\n\t<script>if (!/(\\/|\\.html)$/.test(location.pathname)) location.replace(location.pathname + '/' + location.search + location.hash);</script>"
		. "\n\t<meta name=\"robots\" content=\"noindex, nofollow\">", $html);
	return (string) preg_replace(
		'~<body([^>]*)>~',
		'<body$1>' . "\n" . '<div role="note" style="padding:0.45rem 1rem;font:600 0.9rem/1.3 system-ui,sans-serif;text-align:center;color:#1c2b48;background:#f8af15">'
			. htmlspecialchars(Banner) . '</div>',
		$html,
		1,
	);
}


function write(string $dir, string $file, string $content): void
{
	if (file_put_contents($dir . DIRECTORY_SEPARATOR . $file, $content) === false) {
		throw new RuntimeException("Cannot write $file");
	}
}
