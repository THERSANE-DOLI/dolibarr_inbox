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

-- 1.126.0: authorizations of the modules and events of the journal left by a mailbox deleted before they were
-- deleted with it (audit INBX-100); replayable
DELETE FROM llx_inbox_consumer WHERE fk_account NOT IN (SELECT rowid FROM llx_inbox_account);
DELETE FROM llx_inbox_event WHERE fk_account > 0 AND fk_account NOT IN (SELECT rowid FROM llx_inbox_account);
