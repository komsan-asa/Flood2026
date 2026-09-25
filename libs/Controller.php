<?php

class Controller {

    /** @var View */
    public $view;

    /** @var Model|null */
    public $model;

    function __construct() {
        $this->view = new View();
    }

    public function loadModel($name) {
        $path = 'models/' . $name . '_model.php';
        if (file_exists($path)) {
            require_once $path;
            $modelName = $name . '_Model';
            $this->model = new $modelName();
        }
    }

    public function unloadModel() {
        unset($this->model);
    }

}
