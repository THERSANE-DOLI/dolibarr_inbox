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

-- 1.3.0: state of the synchronisation of a mailbox. Replayed at each activation: a column already there is ignored.
ALTER TABLE llx_inbox_account ADD COLUMN date_sync datetime DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN date_folders datetime DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN sync_lock datetime DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN sync_retry_at datetime DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN sync_fail_count integer DEFAULT 0 NOT NULL;
