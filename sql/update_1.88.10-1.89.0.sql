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

-- 1.89.0: protected mailboxes, the password is encrypted for keys kept in the browsers of its users (never in clear
-- for the server); protect_gen goes up when the password is typed again after a refusal of the mail server
ALTER TABLE llx_inbox_account ADD COLUMN protected smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN protect_gen integer DEFAULT 0 NOT NULL;
-- Level of the protection: password (only the password leaves the server, the mailbox works as the others while a
-- session unlocked it) or content (nothing of the mailbox kept, read live)
ALTER TABLE llx_inbox_account ADD COLUMN protect_level varchar(16) DEFAULT 'password' NOT NULL;
