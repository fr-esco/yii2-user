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
use dektrium\user\models\Account;
use dektrium\user\models\LoginForm;
use dektrium\user\models\Token;
use dektrium\user\models\User;
use dektrium\user\Module;
use dektrium\user\traits\EventTrait;
use Yii;
use yii\authclient\AuthAction;
use yii\authclient\ClientInterface;
use yii\filters\AccessControl;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\web\ConflictHttpException;

/**
 * Controller that manages user authentication process.
 *
 * @property Module $module
 *
 * @author Francesco Colamonici <f.colamonici@gmail.com>
 */
class SecurityController extends Controller
{
    use EventTrait;

    /**
     * Event is triggered before logging user in.
     * Triggered with \dektrium\user\events\FormEvent.
     */
    const EVENT_BEFORE_LOGIN = 'beforeLogin';

    /**
     * Event is triggered after logging user in.
     * Triggered with \dektrium\user\events\FormEvent.
     */
    const EVENT_AFTER_LOGIN = 'afterLogin';

    /**
     * Event is triggered before logging user out.
     * Triggered with \dektrium\user\events\UserEvent.
     */
    const EVENT_BEFORE_LOGOUT = 'beforeLogout';

    /**
     * Event is triggered after logging user out.
     * Triggered with \dektrium\user\events\UserEvent.
     */
    const EVENT_AFTER_LOGOUT = 'afterLogout';

    /**
     * Event is triggered before authenticating user via social network.
     * Triggered with \dektrium\user\events\AuthEvent.
     */
    const EVENT_BEFORE_AUTHENTICATE = 'beforeAuthenticate';

    /**
     * Event is triggered after authenticating user via social network.
     * Triggered with \dektrium\user\events\AuthEvent.
     */
    const EVENT_AFTER_AUTHENTICATE = 'afterAuthenticate';

    /**
     * Event is triggered before connecting social network account to user.
     * Triggered with \dektrium\user\events\AuthEvent.
     */
    const EVENT_BEFORE_CONNECT = 'beforeConnect';

    /**
     * Event is triggered before connecting social network account to user.
     * Triggered with \dektrium\user\events\AuthEvent.
     */
    const EVENT_AFTER_CONNECT = 'afterConnect';


    /** @var Finder */
    protected $finder;

    /**
     * @param string $id
     * @param Module $module
     * @param Finder $finder
     * @param array $config
     */
    public function __construct($id, $module, Finder $finder, $config = [])
    {
        $this->finder = $finder;
        parent::__construct($id, $module, $config);
    }

    /** @inheritdoc */
    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            'authenticator' => [
                'optional' => ['login', 'auth'],
                'except' => ['blocked'],
            ],
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    ['allow' => true, 'actions' => ['login', 'auth', 'blocked'], 'roles' => ['?']],
                    ['allow' => true, 'actions' => ['login', 'auth', 'logout'], 'roles' => ['@']],
                ],
            ],
        ]);
    }

    /** @inheritdoc */
    public function actions()
    {
        return [
            'options' => [
                'class' => 'yii\rest\OptionsAction',
            ],
            'auth' => [
                'class' => AuthAction::className(),
                // if user is not logged in, will try to log him in, otherwise
                // will try to connect social account to user.
                'successCallback' => Yii::$app->user->isGuest
                    ? [$this, 'authenticate']
                    : [$this, 'connect'],
            ],
        ];
    }

    /** @inheritdoc */
    protected function verbs()
    {
        return [
            'login' => ['POST'],
            'logout' => ['POST'],
            'auth' => ['POST'],
            'blocked' => ['POST'],
        ];
    }

    /**
     * Displays the login page.
     *
     * @return Token|LoginForm
     */
    public function actionLogin()
    {
        if (!Yii::$app->user->isGuest) {
            return true;
        }

        /** @var LoginForm $model */
        $model = Yii::createObject(LoginForm::className());
        $event = $this->getFormEvent($model);

        $this->trigger(self::EVENT_BEFORE_LOGIN, $event);

        if ($model->load(Yii::$app->request->getBodyParams(), '') && $model->login()) {
            $this->trigger(self::EVENT_AFTER_LOGIN, $event);

            return $model->user->generateAccessToken();
        }

        return $model;
    }

    /**
     * Logs the user out and then redirects to the homepage.
     *
     * @return bool
     * @throws ConflictHttpException
     */
    public function actionLogout()
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;
        $event = $this->getUserEvent($user);

        $this->trigger(self::EVENT_BEFORE_LOGOUT, $event);

        if (Yii::$app->user->logout()) {
            $this->trigger(self::EVENT_AFTER_LOGOUT, $event);

            return $user->clearCurrentAccessToken();
        }

        throw new ConflictHttpException;
    }

    /**
     * Tries to authenticate user via social network. If user has already used
     * this network's account, he will be logged in. Otherwise, it will try
     * to create new user account.
     *
     * @param ClientInterface $client
     *
     * @throws ConflictHttpException
     */
    public function authenticate(ClientInterface $client)
    {
        $account = $this->finder->findAccount()->byClient($client)->one();

        if (!$this->module->enableRegistration && ($account === null || $account->user === null)) {
            throw new ConflictHttpException(Yii::t('user', 'Registration on this website is disabled'));
        }

        if ($account === null) {
            /** @var Account $account */
            $accountObj = Yii::createObject(Account::className());
            $account = $accountObj::create($client);
        }

        $event = $this->getAuthEvent($account, $client);

        $this->trigger(self::EVENT_BEFORE_AUTHENTICATE, $event);

        if ($account->user instanceof User) {
            if ($account->user->isBlocked) {
                throw new ConflictHttpException(Yii::t('user', 'Your account has been blocked.'));
            } else {
                Yii::$app->user->login($account->user, $this->module->rememberFor);
                $this->action->successUrl = Yii::$app->user->getReturnUrl();
            }
        } else {
            $this->action->successUrl = $account->getConnectUrl(true);
        }

        $this->trigger(self::EVENT_AFTER_AUTHENTICATE, $event);
    }

    /**
     * Tries to connect social account to user.
     *
     * @param ClientInterface $client
     */
    public function connect(ClientInterface $client)
    {
        /** @var Account $account */
        $account = Yii::createObject(Account::className());
        $event = $this->getAuthEvent($account, $client);

        $this->trigger(self::EVENT_BEFORE_CONNECT, $event);

        if ($account->connectWithUser($client, true)) {
            $this->trigger(self::EVENT_AFTER_CONNECT, $event);

            $this->action->successUrl = Url::to(['/user/rest/settings/networks']);
        }
    }
}
