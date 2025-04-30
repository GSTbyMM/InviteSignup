<?php

use MediaWiki\MediaWikiServices;

class InviteSignupHooks {
	public static function onBeforeInitialize(
        Title $title,
        &$unused,
        &$output,
        &$user,
        WebRequest $request
    ) {
        if ( !$title->isSpecialPage() ) {
            return true;
        }
    
        [ $name ] = MediaWikiServices::getInstance()
            ->getSpecialPageFactory()
            ->resolveAlias( $title->getDBkey() );
    
        if ( $name !== 'CreateAccount' ) {
            return true;
        }

        $hash = $request->getVal( 'invite', $request->getCookie( 'invite' ) );
        $session = $request->getSession();

        if ( $hash ) {
            $store = new InviteStore(
                MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA ),
                'invitesignup'
            );
            $invite = $store->getInvite( $hash );
            if ( $invite && $invite['used'] === null ) {
                global $wgInviteSignupHash;
                $wgInviteSignupHash = $hash;
                // Store in session for POST requests
                $session->set( 'InviteSignupHash', $hash );
            }
        } else {
            // On POST, restore from session if present
            $hash = $session->get( 'InviteSignupHash' );
            if ( $hash ) {
                global $wgInviteSignupHash;
                $wgInviteSignupHash = $hash;
            }
        }
    
        return true;
    }

	public static function onUserGetRights( $user, &$rights ) {
        global $wgInviteSignupHash;
        if ( $wgInviteSignupHash !== null ) {
            $rights[] = 'createaccount';
        }
    }

	public static function onUserCreateForm( &$template ) {
        global $wgInviteSignupHash;
        if ( $wgInviteSignupHash === null ) {
            return true;
        }
        $template->data['link'] = null;
        $template->data['useemail'] = false;

        // Add invite hash as hidden field
        if ( isset( $template->data['extraInput'] ) ) {
            $template->data['extraInput'] .= Html::hidden( 'invite', $wgInviteSignupHash );
        } else {
            $template->data['extraInput'] = Html::hidden( 'invite', $wgInviteSignupHash );
        }
    }

	public static function onAddNewAccount( User $user ) {
        global $wgInviteSignupHash;
        if ( $wgInviteSignupHash === null ) {
            return true;
        }

        $store = new InviteStore(
            MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY ),
            'invitesignup'
        );

        $invite = $store->getInvite( $wgInviteSignupHash );

        MediaWikiServices::getInstance()
            ->getUserOptionsManager()
            ->setOption( $user, 'is-inviter', $invite['inviter'] );

        $user->setEmail( $invite['email'] );
        $user->confirmEmail();
        $userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
        foreach ( $invite['groups'] as $group ) {
            if ( isset( $invite['expiry'] ) && $invite['expiry'] ) {
            $userGroupManager->addUserToGroup( $user, $group, $invite['expiry'] );
            } else {
                $userGroupManager->addUserToGroup( $user, $group );
            }
        }
        $user->saveSettings();
        $store->addSignupDate( $user, $wgInviteSignupHash );
        global $wgRequest;
        $wgRequest->response()->setCookie( 'invite', '', time() - 86400 );

        // Clear session variable
        $session = $wgRequest->getSession();
        $session->remove( 'InviteSignupHash' );
    }

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$dir = dirname( __DIR__ ) . '/sql';
		$type = $updater->getDB()->getType();

		$supportedTypes = [ 'mysql', 'postgres', 'sqlite' ];
		if ( !in_array( $type, $supportedTypes ) ) {
			throw new MWException( "InviteSignup does not support $type yet." );
		}
    
		$updater->addExtensionTable( 'invitesignup', "$dir/$type/invitesignup.sql" );
		$updater->addExtensionUpdate( [ 'addTable', 'invitesignup', "$dir/invitesignup.sql", true ] );
        $updater->addExtensionUpdate( [ 'addField', 'invitesignup', 'expiry', "$dir/patch-add-expiry.sql", true ] );
	}
	
	public static function onGetUserPermissionsErrorsExpensive( $title, $user, $action, &$result ) {
    global $wgInviteSignupHash;
    if (
        $action === 'createaccount' &&
        $wgInviteSignupHash !== null &&
        $title->isSpecial( 'CreateAccount' )
    ) {
        // Remove permission error for invited users
        $result = [];
        return false;
    }
    return true;
    }
}
