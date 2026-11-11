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

-- 1.85.0: blocked senders, state of their Sieve block on each mailbox
ALTER TABLE llx_inbox_account ADD COLUMN blocked_hash varchar(40) DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN blocked_state varchar(16) DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN blocked_error varchar(255) DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN blocked_date datetime DEFAULT NULL;
