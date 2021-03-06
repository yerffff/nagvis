<?php
/*****************************************************************************
 *
 * CoreLogonMultisite.php - Module for handling cookie based logins as
 *                          generated by multisite
 *
 * Copyright (c) 2004-2015 NagVis Project (Contact: info@nagvis.org)
 *
 * License:
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 *****************************************************************************/

class CoreLogonMultisite extends CoreLogonModule {
    private $htpasswdPath;
    private $serialsPath;
    private $secretPath;
    private $authFile;

    public function __construct() {
        $this->htpasswdPath = cfg('global', 'logon_multisite_htpasswd');
        $this->serialsPath  = cfg('global', 'logon_multisite_serials');
        $this->secretPath   = cfg('global', 'logon_multisite_secret');

        // When the auth.serial file exists, use this instead of the htpasswd
        // for validating the cookie. The structure of the file is equal, so
        // the same code can be used.
        if(file_exists($this->serialsPath)) {
            $this->authFile = 'serial';

        } elseif(file_exists($this->htpasswdPath)) {
            $this->authFile = 'htpasswd';

        } else {
            throw new NagVisException(l('LogonMultisite: The htpasswd file &quot;[HTPASSWD]&quot; or '
                                       .'the authentication serial file &quot;[SERIAL]&quot; do not exist.',
                          array('HTPASSWD' => $this->htpasswdPath, 'SERIAL' => $this->serialsPath)));
        }

        if(!file_exists($this->secretPath)) {
            $this->redirectToLogin();
        }
    }

    private function loadAuthFile($path) {
        $creds = array();
        foreach(file($path) AS $line) {
            if(strpos($line, ':') !== false) {
                list($username, $secret) = explode(':', $line, 2);
                $creds[$username] = rtrim($secret);
            }
        }
        return $creds;
    }

    private function loadSecret() {
        return trim(file_get_contents($this->secretPath));
    }

    private function generateHash($username, $now, $user_secret) {
        $secret = $this->loadSecret();
        return md5($username . $now . $user_secret . $secret);
    }

    private function checkAuthCookie($cookieName) {
        if(!isset($_COOKIE[$cookieName]) || $_COOKIE[$cookieName] == '') {
            throw new Exception();
        }

        list($username, $issueTime, $cookieHash) = explode(':', $_COOKIE[$cookieName], 3);

        if($this->authFile == 'htpasswd')
            $users = $this->loadAuthFile($this->htpasswdPath);
        else
            $users = $this->loadAuthFile($this->serialsPath);

        if(!isset($users[$username])) {
            throw new Exception();
        }
        $user_secret = $users[$username];

        // Validate the hash
        if($cookieHash != $this->generateHash($username, $issueTime, (string) $user_secret)) {
            throw new Exception();
        }

        // FIXME: Maybe renew the cookie here too

        return $username;
    }

    private function checkAuth() {
        // Loop all cookies trying to fetch a valid authentication
        // cookie for this installation
        foreach(array_keys($_COOKIE) AS $cookieName) {
            if(substr($cookieName, 0, 5) != 'auth_') {
                continue;
            }
            try {
                $name = $this->checkAuthCookie($cookieName);

                session_start();
                $_SESSION['multisiteLogonCookie'] = $cookieName;
                session_write_close();

                return $name;
            } catch(Exception $e) {}
        }
        return '';
    }

    private function redirectToLogin() {
        // Do not redirect on ajax calls. Print out errors instead
        if(CONST_AJAX) {
            throw new NagVisException(l('LogonMultisite: Not authenticated.'));
        }
        // FIXME: Get the real path to multisite
        header('Location:../../../check_mk/login.py?_origtarget=' . urlencode($_SERVER['REQUEST_URI']));
    }

    public function check($printErr = true) {
        global $AUTH, $CORE;

        $username = $this->checkAuth();
        if($username === '') {
            $this->redirectToLogin();
            return false;
        }

        // Check if the user exists
        if($this->verifyUserExists($username,
                        cfg('global', 'logon_multisite_createuser'),
                        cfg('global', 'logon_multisite_createrole'),
                        $printErr) === false) {
            return false;
        }

        $AUTH->setTrustUsername(true);
        $AUTH->setLogoutPossible(false);
        $AUTH->passCredentials(Array('user' => $username));
        return $AUTH->isAuthenticated();
    }
}

?>
