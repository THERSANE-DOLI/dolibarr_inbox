-- Copyright (C) 2026	John BOTELLA
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- A mailbox managed by inbox (one connector: imap for now). Secrets are dolEncrypt-ed and never sent to the browser.
CREATE TABLE llx_inbox_account(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(128) NOT NULL,
	email varchar(255) NOT NULL,
	label varchar(255) DEFAULT NULL,
	type varchar(16) DEFAULT 'shared' NOT NULL,
	connector varchar(32) DEFAULT 'imap' NOT NULL,
	connector_config text DEFAULT NULL,
	fk_user_owner integer DEFAULT NULL,
	imap_host varchar(255) DEFAULT NULL,
	imap_port integer DEFAULT 993,
	imap_encryption varchar(8) DEFAULT 'ssl',
	imap_auth_mode varchar(16) DEFAULT 'login' NOT NULL,
	oauth_service varchar(128) DEFAULT NULL,
	imap_login varchar(255) DEFAULT NULL,
	imap_password text DEFAULT NULL,
	imap_validate_cert smallint DEFAULT 1 NOT NULL,
	smtp_host varchar(255) DEFAULT NULL,
	smtp_port integer DEFAULT 465,
	smtp_encryption varchar(8) DEFAULT 'ssl',
	smtp_same_credentials smallint DEFAULT 1 NOT NULL,
	smtp_login varchar(255) DEFAULT NULL,
	smtp_password text DEFAULT NULL,
	sieve_port integer DEFAULT 4190,
	server_info text DEFAULT NULL,
	folder_roles text DEFAULT NULL,
	sync_max_days integer DEFAULT 0,
	sync_max_mails integer DEFAULT 0,
	tags_mode varchar(8) DEFAULT 'global' NOT NULL,
	usage_profile varchar(16) DEFAULT 'auto' NOT NULL,
	usage_indicators text DEFAULT NULL,
	carrier_category integer DEFAULT NULL,
	color varchar(7) DEFAULT NULL,
	icon varchar(48) DEFAULT NULL,
	status smallint DEFAULT 0 NOT NULL,
	date_last_test datetime DEFAULT NULL,
	last_test_result varchar(16) DEFAULT NULL,
	last_error text DEFAULT NULL,
	sync_state varchar(16) DEFAULT NULL,
	sync_error text DEFAULT NULL,
	date_sync_error datetime DEFAULT NULL,
	date_sync datetime DEFAULT NULL,
	date_folders datetime DEFAULT NULL,
	sync_lock datetime DEFAULT NULL,
	sync_retry_at datetime DEFAULT NULL,
	away_from date DEFAULT NULL,
	away_to date DEFAULT NULL,
	sync_fail_count integer DEFAULT 0 NOT NULL,
	quota_used bigint DEFAULT NULL,
	quota_limit bigint DEFAULT NULL,
	blocked_hash varchar(40) DEFAULT NULL,
	blocked_state varchar(16) DEFAULT NULL,
	blocked_error varchar(255) DEFAULT NULL,
	blocked_date datetime DEFAULT NULL,
	protected smallint DEFAULT 0 NOT NULL,
	protect_gen integer DEFAULT 0 NOT NULL,
	protect_level varchar(16) DEFAULT 'password' NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer DEFAULT NULL,
	import_key varchar(14) DEFAULT NULL
) ENGINE=innodb;
