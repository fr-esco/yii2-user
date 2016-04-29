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

use dektrium\user\Finder;
use dektrium\user\controllers\rest\BaseController as Controller;
use dektrium\user\models\Profile;
use dektrium\user\models\SettingsForm;
use dektrium\user\Module;
use dektrium\user\traits\EventTrait;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * SettingsController manages updating user settings (e.g. profile, email and password).
 *
 * @property \dektrium\user\Module $module
 *
 * @author Francesco Colamonici <f.colamonici@gmail.com>
 */
class SettingsController extends Controller
{
    use EventTrait;

    /**
     * Event is triggered before updating user's profile.
     * Triggered with \dektrium\user\events\UserEvent.
     */
    const EVENT_BEFORE_PROFILE_UPDATE = 'beforeProfileUpdate';

    /**
     * Event is triggered after updating user's profile.
     * Triggered with \dektrium\user\events\UserEvent.
     */
    const EVENT_AFTER_PROFILE_UPDATE = 'afterProfileUpdate';

    /**
     * Event is triggered before updating user's account settings.
     * Triggered with \dektrium\user\events\FormEvent.
     */
    const EVENT_BEFORE_ACCOUNT_UPDATE = 'beforeAccountUpdate';

    /**
     * Event is triggered after updating user's account settings.
     * Triggered with \dektrium\user\events\FormEvent.
     */
    const EVENT_AFTER_ACCOUNT_UPDATE = 'afterAccountUpdate';

    /**
     * Event is triggered before changing users' email address.
     * Triggered with \dektrium\user\events\UserEvent.
     */
    const EVENT_BEFORE_CONFIRM = 'beforeConfirm';

    /**
     * Event is triggered after changing users' email address.
     * Triggered with \dektrium\user\events\UserEvent.
     */
    const EVENT_AFTER_CONFIRM = 'afterConfirm';

    /**
     * Event is triggered before disconnecting social account from user.
     * Triggered with \dektrium\user\events\ConnectEvent.
     */
    const EVENT_BEFORE_DISCONNECT = 'beforeDisconnect';

    /**
     * Event is triggered after disconnecting social account from user.
     * Triggered with \dektrium\user\events\ConnectEvent.
     */
    const EVENT_AFTER_DISCONNECT = 'afterDisconnect';

    /** @inheritdoc */
    public $defaultAction = 'profile';

    /** @var Finder */
    protected $finder;

    /**
     * @param string           $id
     * @param \yii\base\Module $module
     * @param Finder           $finder
     * @param array            $config
     */
    public function __construct($id, $module, Finder $finder, $config = [])
    {
        $this->finder = $finder;
        parent::__construct($id, $module, $config);
    }

    /** @inheritdoc */
    public function actions()
    {
        return [
            'options' => [
                'class' => 'yii\rest\OptionsAction',
            ],
        ];
    }

    /** @inheritdoc */
    protected function verbs()
    {
        return [
            'profile' => ['POST'],
            'account' => ['POST'],
            'networks' => ['GET', 'HEAD'],
            'disconnect' => ['POST'],
            'confirm' => ['POST'],
        ];
    }

    /** @inheritdoc */
    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow'   => true,
                        'actions' => ['profile', 'account', 'networks', 'disconnect'],
                        'roles'   => ['@'],
                    ],
                    [
                        'allow'   => true,
                        'actions' => ['confirm'],
                        'roles'   => ['?', '@'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Shows profile settings form.
     *
     * @return string|\yii\web\Response
     */
    public function actionProfile()
    {
        $model = $this->finder->findProfileById(Yii::$app->user->identity->getId());

        if ($model == null) {
            $model = Yii::createObject(Profile::className());
            $model->link('user', Yii::$app->user->identity);
        }

        $event = $this->getProfileEvent($model);

        $this->trigger(self::EVENT_BEFORE_PROFILE_UPDATE, $event);
        if ($model->load(Yii::$app->request->post(), '') && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('user', 'Your profile has been updated'));
            $this->trigger(self::EVENT_AFTER_PROFILE_UPDATE, $event);
            return $this->refresh();
        }

        return $model;
    }

    /**
     * Displays page where user can update account settings (username, email or password).
     *
     * @return string|\yii\web\Response
     */
    public function actionAccount()
    {
        /** @var SettingsForm $model */
        $model = Yii::createObject(SettingsForm::className());
        $event = $this->getFormEvent($model);

        $this->trigger(self::EVENT_BEFORE_ACCOUNT_UPDATE, $event);
        if ($model->load(Yii::$app->request->post(), '') && $model->save()) {
            Yii::$app->session->setFlash('success', Yii::t('user', 'Your account details have been updated'));
            $this->trigger(self::EVENT_AFTER_ACCOUNT_UPDATE, $event);
            return $this->refresh();
        }

        return $model;
    }

    /**
     * Attempts changing user's email address.
     *
     * @param int    $id
     * @param string $code
     *
     * @return string
     * @throws \yii\web\HttpException
     */
    public function actionConfirm($id, $code)
    {
        $user = $this->finder->findUserById($id);

        if ($user === null || $this->module->emailChangeStrategy == Module::STRATEGY_INSECURE) {
            throw new NotFoundHttpException();
        }

        $event = $this->getUserEvent($user);

        $this->trigger(self::EVENT_BEFORE_CONFIRM, $event);
        $user->attemptEmailChange($code);
        $this->trigger(self::EVENT_AFTER_CONFIRM, $event);

        return $this->redirect(['account']);
    }

    /**
     * Displays list of connected network accounts.
     *
     * @return string
     */
    public function actionNetworks()
    {
        return $this->render('networks', [
            'user' => Yii::$app->user->identity,
        ]);
    }

    /**
     * Disconnects a network account from user.
     *
     * @param int $id
     *
     * @return \yii\web\Response
     * @throws \yii\web\NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionDisconnect($id)
    {
        $account = $this->finder->findAccount()->byId($id)->one();

        if ($account === null) {
            throw new NotFoundHttpException();
        }
        if ($account->user_id != Yii::$app->user->id) {
            throw new ForbiddenHttpException();
        }

        $event = $this->getConnectEvent($account, $account->user);

        $this->trigger(self::EVENT_BEFORE_DISCONNECT, $event);
        $account->delete();
        $this->trigger(self::EVENT_AFTER_DISCONNECT, $event);

        return $this->redirect(['networks']);
    }
}
