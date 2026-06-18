<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

$request = $_REQUEST;
$view = !empty($_GET['view']) ? $_GET['view'] : '';
$lw = \FreePBX::Loneworker();

switch ($view) {
	case 'settings':
		$heading = _('Lone Worker: Settings');
		$content = load_view(__DIR__ . '/views/settings.php', ['settings' => $lw->getSettings()]);
	break;
	case 'events':
		$heading = _('Lone Worker: Event history');
		$content = load_view(__DIR__ . '/views/events.php', ['events' => $lw->getEventsForView(200)]);
	break;
	default:
		$heading = _('Lone Worker: Active sessions');
		$content = load_view(__DIR__ . '/views/grid.php', ['settings' => $lw->getSettings()]);
	break;
}
?>
<div class="container-fluid">
	<h1><?php echo $heading ?></h1>
	<div class="display full-border">
		<div class="row">
			<div class="col-sm-12">
				<div class="fpbx-container">
					<div class="display full-border">
						<?php echo $content ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
