<?php

namespace app\controllers;

use \Yii;
use \app\models\Dump;

class DumpController extends \yii\console\Controller
{
    public function actionIndex($link, $depth = 0, $buffer = 0, $force = false, $searchExternal = false, $path = '')
    {
        (new Dump)->download($link, $depth, $buffer, $force, $searchExternal, $path);

        return 0;
    }
}
