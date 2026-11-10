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

-- 1.72.0: requester of a mail sent by a site (data sources, lot A2 level 1)
ALTER TABLE llx_inbox_mail ADD COLUMN requester_email varchar(255) DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN requester_name varchar(255) DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN fk_source integer DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN source_data text DEFAULT NULL;
-- Here, not in llx_inbox_mail.key.sql: the keys are loaded before the updates, the column is not there yet on an upgrade
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_requester (requester_email);
