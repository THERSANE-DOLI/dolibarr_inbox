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


-- 1.145.0: failed passes in a row of a folder; after InboxFolder::FAILS_SET_ASIDE, the folder no longer keeps its
-- mailbox in « first synchronisation » (a folder of the mail server that can never be read, as the storage of Sieve)
ALTER TABLE llx_inbox_folder ADD COLUMN sync_fail_count integer DEFAULT 0 NOT NULL AFTER sync_error;
