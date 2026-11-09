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

-- Lot W3 (1.4.0): change date of a row of the index (refresh of the open folder)
ALTER TABLE llx_inbox_mail ADD COLUMN tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE llx_inbox_mail ADD INDEX idx_inbox_mail_tms (fk_folder, tms);
-- Lot W3 (1.4.0): quota of the mailbox in KB, read with the folders (NULL: no quota given by the server)
ALTER TABLE llx_inbox_account ADD COLUMN quota_used bigint DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN quota_limit bigint DEFAULT NULL;
