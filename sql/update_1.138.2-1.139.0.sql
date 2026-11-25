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

-- 1.139.0: a mail that starts a new conversation (cut point, by Message-ID like thread_detached): the climb up its
-- references stops on it. 1 cut by a user, 2 cut because its subject changed, -1 joined back by a user (never cut
-- again automatically)
ALTER TABLE llx_inbox_mailref ADD COLUMN thread_root smallint DEFAULT 0 NOT NULL;
