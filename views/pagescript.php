<?php
if (isset($this->css)) {
    foreach ($this->css as $css) {
        $cssPath = 'views/' . $css;
        $ver = @filemtime($cssPath) ?: time();
        echo '<link rel="stylesheet" href="' . URL . $cssPath . '?v=' . $ver . '">' . "\n";
    }
}
if (isset($this->js)) {
    foreach ($this->js as $js) {
        $jsPath = 'views/' . $js;
        $ver = @filemtime($jsPath) ?: time();
        echo '<script type="text/javascript" src="' . URL . $jsPath . '?v=' . $ver . '"></script>' . "\n";
    }
}
