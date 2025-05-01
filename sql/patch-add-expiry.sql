ALTER TABLE invitesignup ADD COLUMN expiry varbinary(32) DEFAULT NULL;
ALTER TABLE invitesignup MODIFY is_invitee int(10) unsigned NULL;
ALTER TABLE invitesignup MODIFY is_used binary(14) NULL;
