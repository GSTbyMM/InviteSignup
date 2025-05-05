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
        $expiry = $params['expiry'] ?? null;         // Expiry timestamp (YmdHis format expected)
        $orderDate = $params['orderdate'] ?? null;  // Order date (YmdHis from Prestashop)
        $targetGroup = 'paid'; // The target user group for renewal

        $logFile = __DIR__ . '/invitesignup_api.log';

        if (!Sanitizer::validateEmail($email)) {
            $this->dieWithError('Invalid email address', 'bademail');
        }

        // 1. Check InviteSignup DB for existing invitee for this email
        $store = new InviteStore(
            MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_PRIMARY),
            'invitesignup'
        );
        $inviteeId = $this->findInviteeIdByEmail($store, $email);

        if (!$inviteeId) {
            // No user exists for this email, send invite as before
            $inviter = User::newSystemUser('InviteBot', [ 'steal' => true ]);
            $hash = $store->addInvite($inviter, $email, $groups, $expiry);
            SpecialInviteSignup::sendInviteEmail($inviter, $email, $hash);

            file_put_contents($logFile, date('c') . " | $email | INVITE SENT | expiry: $expiry\n", FILE_APPEND);
            $this->getResult()->addValue(null, $this->getModuleName(), [
                'result' => 'invite_sent', 'hash' => $hash
            ]);
            return;
        }

        // 2. User exists: check current group membership and expiry
        $user = User::newFromId($inviteeId);
        $userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
        $currentGroups = $userGroupManager->getUserGroups($user);

        // Fetch existing membership expiries using getUserGroupMemberships()
        // This returns an array (group => UserGroupMembership object):contentReference[oaicite:2]{index=2}.
        $memberships = $userGroupManager->getUserGroupMemberships($user);
        $existingExpiry = null;
        if (isset($memberships[$targetGroup])) {
            // If user is in the group, get the expiry timestamp (YmdHis string or null)
            $existingExpiry = $memberships[$targetGroup]->getExpiry();
        }

        $now = wfTimestampNow();
        file_put_contents($logFile, date('c') . " | $email | EXISTING EXPIRY: $existingExpiry | NOW: $now | ORDERDATE: $orderDate | REQ EXPIRY: $expiry\n", FILE_APPEND);

        // If user is not currently in group, or has no expiry set, or membership has expired
        if (!in_array($targetGroup, $currentGroups) || !$existingExpiry || $existingExpiry < $now) {
            // (Re-)add the user to the group with the new expiry from pswiki
            // addUserToGroup will create or update the membership with the given expiry:contentReference[oaicite:3]{index=3}.
            $userGroupManager->addUserToGroup($user, $targetGroup, $expiry);
            file_put_contents($logFile, date('c') . " | $email | GROUP ADDED/RENEWED | expiry set to: $expiry\n", FILE_APPEND);
            $this->getResult()->addValue(null, $this->getModuleName(), [
                'result' => 'group_added_or_renewed', 'expiry' => $expiry
            ]);
            return;
        }

        // 3. User is in group and membership not expired: extend expiry by the renewal period
        // Calculate period (in seconds) = (new expiry - order date)
        if (!$orderDate) {
            // If no order date provided, assume renewal starts now
            $orderDate = $now;
        }
        $periodSeconds = wfTimestamp(TS_UNIX, $expiry) - wfTimestamp(TS_UNIX, $orderDate);

        // Add the period to existing expiry
        $existingExpiryUnix = wfTimestamp(TS_UNIX, $existingExpiry);
        $newExpiryUnix = $existingExpiryUnix + $periodSeconds;
        $newExpiry = wfTimestamp(TS_MW, $newExpiryUnix);

        file_put_contents($logFile, date('c') . " | $email | EXTEND: existingExpiry: $existingExpiry | periodSeconds: $periodSeconds | newExpiry: $newExpiry\n", FILE_APPEND);

        // **CHANGED**: Remove user from group before re-adding to apply new expiry
        $userGroupManager->removeUserFromGroup($user, $targetGroup);
        // Re-add user with the extended expiry timestamp
        $userGroupManager->addUserToGroup($user, $targetGroup, $newExpiry);
        $this->getResult()->addValue(null, $this->getModuleName(), [
            'result'     => 'group_extended',
            'old_expiry' => $existingExpiry,
            'new_expiry' => $newExpiry
        ]);
    }

    /**
     * Find the user ID (invitee) for a given email from the InviteSignup DB.
     * Returns user ID or null.
     */
    private function findInviteeIdByEmail($store, $email) {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $table = $store->getTableName();
        $row = $dbr->selectRow(
            $table,
            [ 'is_invitee' ],
            [ 'is_email' => $email, 'is_invitee IS NOT NULL' ],
            __METHOD__,
            [ 'ORDER BY' => 'is_used DESC' ]
        );
        return $row && $row->is_invitee ? (int)$row->is_invitee : null;
    }

    public function getAllowedParams() {
        return [
            'secret'    => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'email'     => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'groups'    => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => true ],
            'expiry'    => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ],
            'orderdate' => [ self::PARAM_TYPE => 'string', self::PARAM_REQUIRED => false ], // YmdHis
        ];
    }
    public function isWriteMode() {
        return true;
    }
}
