Note: This is fork of the original extension compitable with MediaWiki 1.43.1 with API enabled. This API feature enables sending invites when specific event triggers occur, along with an expiry time if the group is added. For details, refer to includes/ApiInviteSignup.php. 
-----------------

The InviteSignup extensions allows account creation to be offered to a user on a closed wiki where it's restricted.

The extension adds a "Special:InviteSignup" special page, available to users with invitesignup permission.
* The inviter, from this page, by entering just an e-mail address, can quickly invite a single person to create an account on the wiki.
* The person can then set a username and password, confirming the creation of the account, which is logged; until then, no account is created.
* The inviter can optionally set additional user groups to which the account will be added automatically after creation.

== Installation ==
* Download and move the extracted InviteSignup folder to your extensions/ directory.
* Add the following code at the bottom of your LocalSettings.php file: wfLoadExtension( 'InviteSignup' );
* Run the update script which will automatically create the necessary database tables that this extension needs.
* You will need to give the invitesignup permission to at least one user group. To have administrators be able to do the inviting, for instance, add the following to LocalSettings.php: $wgGroupPermissions['sysop']['invitesignup'] = true;
* You can also set the variable $wgISGroups to an array of user groups. When inviting, you can mark to which groups the invited user will be added automatically. For example, with the following setting, you are able to invite 1) normal users 2) translators 3) sysops. $wgISGroups = [ 'translator', 'sysop' ];
* Done – Navigate to Special:Version on your wiki to verify that the extension is successfully installed.

== API Docs ==
* Declare $wgInviteSignupApiSecret = '32 char secret key' in LocalSettings.php for security purposes. Use the same key as a $params['secret'] when sending request.
* Other params are $params['email'] on which email is required to be sent and $params['expiry'] in 'YmdHis' format.
* Renewel Mechanism implemented where if the existing user (email id) triggers the same event again then:<br>
  * If not expired, new expiry will be added beyond original expiry.<br>
  * If expired, added again to the group with this new expiry.
