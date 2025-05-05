<?php
/**
 * Storage abstraction for invites.
 *
 * @file
 * @ingroup Extensions
 *
 * @author Niklas Laxström
 * @copyright Copyright © 2013 Lost in Translations Inc.
 * @license GPL-2.0-or-later
 */

use Wikimedia\Rdbms\IDatabase;

/**
 * InviteStore which uses a database as storage.
 */
class InviteStore {
	protected $db;
	protected $dbTable;

	public function __construct( IDatabase $db, $table ) {
		$this->db = $db;
		$this->dbTable = $table;
	}
	
	public function getTableName() {
        return $this->dbTable;
    }

	public function getInvites() {
		$fields = [ '*' ];
		$conds = [];
		$res = $this->db->select( $this->dbTable, $fields, $conds, __METHOD__ );
		$invites = [];
		foreach ( $res as $row ) {
			$invites[] = $this->rowToArray( $row );
		}

		return $invites;
	}

	public function addInvite( User $inviter, $email, $groups, $expiry = null ) {
        global $wgSecretKey;
        $hash = sha1( $inviter->getId() . $wgSecretKey . $email . wfTimestamp( TS_UNIX ) );
    
        $data = [
            'is_inviter' => $inviter->getId(),
            'is_email' => $email,
            'is_when' => wfTimestamp( TS_MW ), // Correct timestamp format
            'is_hash' => $hash,
            'is_groups' => serialize( $groups ),
            'is_used' => null, // Ensure this is null initially
            'expiry' => $expiry ?: null,
        ];
    
        $this->db->insert( $this->dbTable, $data, __METHOD__ );
    
        return $hash;
    }

	public function deleteInvite( $hash ) {
		$conds = [ 'is_hash' => $hash ];
		$this->db->delete( $this->dbTable, $conds, __METHOD__ );
	}

	public function getInvite( $hash ) {
		$fields = [ '*' ];
		$conds = [ 'is_hash' => $hash ];
		$res = $this->db->selectRow( $this->dbTable, $fields, $conds, __METHOD__ );

		return $this->rowToArray( $res );
	}

	public function addSignupDate( User $user, $hash ) {
        $conds = [ 'is_hash' => $hash ];
        $data = [
            'is_used' => wfTimestamp( TS_MW ), // <-- FIXED: use TS_MW
            'is_invitee' => $user->getId(),
        ];
        $this->db->update( $this->dbTable, $data, $conds, __METHOD__ );
    }

	protected function rowToArray( $row ) {
		$array = [];
		if ( $row === false ) {
			return null;
		}

		foreach ( $row as $key => $value ) {
			if ( $key === 'is_groups' ) {
				$value = unserialize( $value );
			}
			if ( strpos( $key, 'is_' ) === 0 ) {
            $array[substr( $key, 3 )] = $value;
        } else {
            // For columns like 'expiry'
            $array[$key] = $value;
        }
	}

		return $array;
	}
}
