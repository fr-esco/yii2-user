<?php

/*
 * This file is part of the Dektrium project.
 *
 * (c) Dektrium project <http://github.com/dektrium/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace dektrium\user\controllers\rest;

use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBearerAuth;
use yii\helpers\ArrayHelper;
use yii\rest\Controller;

/**
 * @property \dektrium\user\Module $module
 *
 * @author Francesco Colamonici <f.colamonici@gmail.com>
 */
abstract class BaseController extends Controller
{
    /** @inheritdoc */
    public function init()
    {
        parent::init();
        \Yii::$app->user->enableSession = $this->module->enableSessionRest;
    }

    /** @inheritdoc */
    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            'authenticator' => [
                'class' => CompositeAuth::className(),
                'authMethods' => empty($this->module->authMethodsRest) ? [
                    HttpBearerAuth::className(),
                ] : $this->module->authMethodsRest,
            ],
        ]);
    }

}
