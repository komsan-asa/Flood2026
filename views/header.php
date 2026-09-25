<?php
$isApp = isset($this->pageMenu) && $this->pageMenu === 'flood';
$bodyClass = trim(($isApp ? 'flood-app-body ' : 'flood-public-body ') . (isset($this->bodyClass) ? $this->bodyClass : ''));
$pageTitle = isset($this->pageTitle) && $this->pageTitle ? $this->pageTitle . ' · ' . SHORT_NAME_SYSTEM : TITLE_SYSTEM_NAME;
?>
<!DOCTYPE html>
<html lang="th">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0c2f4f" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-title" content="<?= h(SHORT_NAME_SYSTEM) ?>" />
        <meta name="description" content="<?= h(SYSTEM_NAME) ?>" />
        <title><?= h($pageTitle) ?></title>
        <link rel="icon" type="image/svg+xml" href="<?= URL ?>public/img/favicon.svg?v=<?= flood_asset_ver('public/img/favicon.svg') ?>" />
        <link rel="stylesheet" href="<?= URL ?>public/css/tailwind.css?v=<?= flood_asset_ver('public/css/tailwind.css') ?>" type="text/css"/>
        <link rel="stylesheet" href="<?= URL ?>public/css/font-awesome-4.7.0/css/font-awesome.min.css" type="text/css"/>
        <?php if (!$isApp) { ?>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700&display=swap" />
        <link rel="stylesheet" href="<?= URL ?>public/css/sk-pastel.css?v=<?= flood_asset_ver('public/css/sk-pastel.css') ?>" type="text/css"/>
        <?php } ?>
        <link rel="stylesheet" href="<?= URL ?>public/css/flood-extra.css?v=<?= flood_asset_ver('public/css/flood-extra.css') ?>" type="text/css"/>
        <?php if (!empty($this->useMap)) { ?>
        <link rel="stylesheet" href="<?= URL ?>public/leaflet/leaflet.css" type="text/css"/>
        <link rel="stylesheet" href="<?= URL ?>public/css/flood-map.css?v=<?= flood_asset_ver('public/css/flood-map.css') ?>" type="text/css"/>
        <?php } ?>
        <style id="floodLevelCss"><?= flood_level_css() ?></style>
        <script type="text/javascript">
            window.BASE_URL = '<?= URL ?>';
            window.CSRF_TOKEN = <?= flood_js(flood_csrf_token()) ?>;
            window.FLOOD_MAP = <?= flood_js(flood_map_config()) ?>;
            window.FLOOD_UPLOAD_MAX = <?= (int) flood_upload_limit() ?>;
        </script>
        <script src="<?= URL ?>public/js/jquery-2.1.3.min.js" type="text/javascript"></script>
        <script src="<?= URL ?>public/bootstrap-3.3.5/dist/js/bootstrap.min.js" type="text/javascript"></script>
        <?php if (!empty($this->useMap)) { ?>
        <script src="<?= URL ?>public/leaflet/leaflet.js" type="text/javascript"></script>
        <?php } ?>
        <script src="<?= URL ?>public/js/pagejscript.js?v=<?= flood_asset_ver('public/js/pagejscript.js') ?>"></script>
        <?php require 'pagescript.php'; ?>
    </head>
    <body class="<?= h($bodyClass) ?>">
