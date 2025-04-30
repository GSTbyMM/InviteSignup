<?php

use MediaWiki\MediaWikiServices;

class ApiInviteSignup extends ApiBase {
    public function execute() {
        $params = $this->extractRequestParams();

        // Security: Check secret token from LocalSettings.php
        $secret = $params['secret'];
        $expectedSecret = $GLOBALS['wgInviteSignupApiSecret'] ?? '';
        if (!$expectedSecret || $secret !== $expectedSecret) {
            $this->dieWithError('Invalid API secret', 'badsecret');
        }

        $email = $params['email'];
        $groups = array_filter(array_map('trim', explode(',', $params['groups'])));
        $expiry = $params['expiry'] ?? null;

        if (!Sanitizer::validateEmail($email)) {
            $this->dieWithError('Invalid email address', 'bademail');
        }

        // Use a system user as inviter
        $user = User::newSystemUser('InviteBot', [ 'steal' => true ]);
        $store = new InviteStore(
            MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY),
            'invitesignup'
        );
        $hash = $store->addInvite($user, $email, $groups, $expiry);
        SpecialInviteSignup::sendInviteEmail($user, $email, $hash);

        $this->getResult()->addValue(null, $this->getModuleName(), [ 'result' => 'success', 'hash' => $hash ]);
    }

    public function getAllowedParams() {
        return [
            'secret' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'email' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'groups' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'expiry' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ],
        ];
    }

    public function isWriteMode() {
        return true;
    }
}