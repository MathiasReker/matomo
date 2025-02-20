<?php

namespace Piwik\Plugins\UsersManager\Repository;

use Piwik\Auth\Password;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Metrics\Formatter;
use Piwik\Piwik;
use Piwik\Plugins\CoreAdminHome\Emails\UserCreatedEmail;
use Piwik\Plugins\UsersManager\API;
use Piwik\Plugins\UsersManager\Emails\UserInviteEmail;
use Piwik\Plugins\UsersManager\LastSeenTimeLogger;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Plugins\UsersManager\UserAccessFilter;
use Piwik\Plugins\UsersManager\UsersManager;
use Piwik\Plugins\UsersManager\Validators\Email;
use Piwik\Plugins\UsersManager\Validators\Login;
use Piwik\Validators\BaseValidator;
use Piwik\Validators\IdSite;
use Piwik\Plugin;


class UserRepository
{

    protected $model;

    protected $filter;

    protected $password;

    public function __construct(Model $model, UserAccessFilter $filter, Password $password)
    {
        $this->model = $model;
        $this->filter = $filter;
        $this->password = $password;
    }


    public function index($userLogin, $pending)
    {
        Piwik::checkUserHasSuperUserAccessOrIsTheUser($userLogin);
        $this->checkUserExists($userLogin);

        $user = $this->model->getUser($userLogin, $pending);

        $user = $this->filter->filterUser($user);
        return $this->enrichUser($user);
    }

    public function create($userLogin, $email, $initialIdSite, $password = '', $_isPasswordHashed = false)
    {
        $this->validateAccess();
        if (!Piwik::hasUserSuperUserAccess()) {
            if (empty($initialIdSite)) {
                throw new \Exception(Piwik::translate("UsersManager_AddUserNoInitialAccessError"));
            }
            // check if the site exist
            BaseValidator::check('siteId', $initialIdSite, [new IdSite()]);
            Piwik::checkUserHasAdminAccess($initialIdSite);
        }

        //validate info
        BaseValidator::check('userLogin', $userLogin, [new Login(true)]);
        BaseValidator::check('email', $email, [new Email(true)]);

        if (!empty($password)) {
            if (!$_isPasswordHashed) {
                $passwordTransformed = UsersManager::getPasswordHash($password);
            } else {
                $passwordTransformed = $password;
            }
            $password = $this->password->hash($passwordTransformed);
        }

        //insert user into database.
        $this->model->addUser($userLogin, $password, $email, Date::now()->getDatetime(), empty($password));

        /**
         * Triggered after a new user is invited.
         *
         * @param string $userLogin The new user's details handle.
         */
        Piwik::postEvent('UsersManager.inviteUser.end', array($userLogin, $email));

        if ($initialIdSite) {
            API::getInstance()->setUserAccess($userLogin, 'view', $initialIdSite);
        }
    }

    public function sendNewUserEmails($userLogin, $expired = 7, $newUser = true)
    {

        //send Admin Email
        if ($newUser) {
            $mail = StaticContainer::getContainer()->make(UserCreatedEmail::class, array(
              'login'        => Piwik::getCurrentUserLogin(),
              'emailAddress' => Piwik::getCurrentUserEmail(),
              'userLogin'    => $userLogin,
            ));
            $mail->safeSend();
        }


        if (!empty($expired)) {
            //retrieve user details
            $user = API::getInstance()->getUser($userLogin);

            //remove all previous token
            $this->model->deleteAllTokensForUser($userLogin);

            //generate Token
            $generatedToken = $this->model->generateRandomTokenAuth();

            //attach token to user
            $this->model->addTokenAuth($userLogin, $generatedToken, "Invite Token", Date::now()->getDatetime(),
              Date::now()->addDay($expired)->getDatetime());


            // send email
            $email = StaticContainer::getContainer()->make(UserInviteEmail::class, array(
              'currentUser' => Piwik::getCurrentUserLogin(),
              'user'        => $user,
              'token'       => $generatedToken
            ));
            $email->safeSend();
        }
    }

    private function validateAccess()
    {
        Piwik::checkUserHasSomeAdminAccess();
        UsersManager::dieIfUsersAdminIsDisabled();
    }

    public function enrichUser($user)
    {
        if (empty($user)) {
            return $user;
        }

        unset($user['token_auth']);
        unset($user['password']);
        unset($user['ts_password_modified']);
        unset($user['idchange_last_viewed']);

        if ($lastSeen = LastSeenTimeLogger::getLastSeenTimeForUser($user['login'])) {
            $user['last_seen'] = Date::getDatetimeFromTimestamp($lastSeen);
        }

        if (Piwik::hasUserSuperUserAccess()) {
            $user['uses_2fa'] = !empty($user['twofactor_secret']) && $this->isTwoFactorAuthPluginEnabled();
            unset($user['twofactor_secret']);
            if (!empty($user['invite_status']) && $user['invite_status'] === 'pending') {
                $validToken = $this->model->checkUserHasUnexpiredToken($user['login']);
                if (!$validToken) {
                    $user['invite_status'] = 'expired';
                }
            }
            if (empty($user['invite_status'])) {
                $user['invite_status'] = 'accept';
            }
            return $user;
        }

        $newUser = array('login' => $user['login']);

        if ($user['login'] === Piwik::getCurrentUserLogin() || !empty($user['superuser_access'])) {
            $newUser['email'] = $user['email'];
        }

        if (isset($user['role'])) {
            $newUser['role'] = $user['role'] == 'superuser' ? 'admin' : $user['role'];
        }
        if (isset($user['capabilities'])) {
            $newUser['capabilities'] = $user['capabilities'];
        }

        if (isset($user['superuser_access'])) {
            $newUser['superuser_access'] = $user['superuser_access'];
        }

        if (isset($user['last_seen'])) {
            $newUser['last_seen'] = $user['last_seen'];
        }

        return $newUser;
    }

    public function enrichUsers($users)
    {
        if (!empty($users)) {
            foreach ($users as $index => $user) {
                $users[$index] = $this->enrichUser($user);
            }
        }
        return $users;
    }

    public function enrichUsersWithLastSeen($users)
    {
        $formatter = new Formatter();

        $lastSeenTimes = LastSeenTimeLogger::getLastSeenTimesForAllUsers();
        foreach ($users as &$user) {
            $login = $user['login'];
            if (isset($lastSeenTimes[$login])) {
                $user['last_seen'] = $formatter->getPrettyTimeFromSeconds(time() - $lastSeenTimes[$login]);
            }
        }
        return $users;
    }


    private function isTwoFactorAuthPluginEnabled()
    {
        if (!isset($this->twoFaPluginActivated)) {
            $this->twoFaPluginActivated = Plugin\Manager::getInstance()->isPluginActivated('TwoFactorAuth');
        }
        return $this->twoFaPluginActivated;
    }


}