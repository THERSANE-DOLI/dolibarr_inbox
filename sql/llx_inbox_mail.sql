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

-- Index of inbox: one mail at one place (a mail in two folders = two rows, same message_id). Headers only, never the
-- body: the mail server stays the reference. remote_id is opaque (connector), sort_key orders by arrival.
CREATE TABLE llx_inbox_mail(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_account integer NOT NULL,
	fk_folder integer NOT NULL,
	remote_id varchar(128) NOT NULL,
	sort_key bigint DEFAULT 0 NOT NULL,
	message_id varchar(255) DEFAULT NULL,
	in_reply_to varchar(255) DEFAULT NULL,
	refs text DEFAULT NULL,
	date_mail datetime DEFAULT NULL,
	date_received datetime DEFAULT NULL,
	from_email varchar(255) DEFAULT NULL,
	from_name varchar(255) DEFAULT NULL,
	to_text text DEFAULT NULL,
	cc_text text DEFAULT NULL,
	subject varchar(255) DEFAULT NULL,
	snippet varchar(255) DEFAULT NULL,
	size integer DEFAULT 0 NOT NULL,
	has_attachments smallint DEFAULT 0 NOT NULL,
	seen smallint DEFAULT 0 NOT NULL,
	answered smallint DEFAULT 0 NOT NULL,
	flagged smallint DEFAULT 0 NOT NULL,
	draft smallint DEFAULT 0 NOT NULL,
	keywords varchar(1024) DEFAULT NULL,
	fk_soc integer DEFAULT NULL,
	fk_socpeople integer DEFAULT NULL,
	date_index datetime NOT NULL
) ENGINE=innodb;
