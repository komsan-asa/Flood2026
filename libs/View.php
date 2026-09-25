<?php

#[AllowDynamicProperties]
class View {

    /** @var string */
    public $pageMenu = '';

    /** @var string */
    public $activeTab = '';

    /** @var array */
    public $js = array();

    /** @var array */
    public $css = array();

    /** @var string */
    public $msg = '';

    function __construct() {
    }

    /** PHP 8.2+: อ่าน prop ที่ยังไม่ถูกตั้งค่าโดยไม่โยน Warning */
    public function __get($name) {
        return null;
    }

    public function __isset($name) {
        return false;
    }

    public function rander($name, $noInclude = false) {
        set_time_limit(180);
        ini_set('max_execution_time', 180);
        if ($noInclude == true) {
            require 'views/pagescript.php';
            require 'views/' . $name . '.php';
        } else {
            require 'views/header.php';
            require 'views/menutop.php';
            require 'views/' . $name . '.php';
            require 'views/footer.php';
        }
    }

}
