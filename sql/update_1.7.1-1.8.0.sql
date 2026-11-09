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

-- 1.8.0: colour and icon of a mailbox and of its folders, shared by the users of the mailbox (empty = by default)
ALTER TABLE llx_inbox_account ADD COLUMN color varchar(7) DEFAULT NULL;
ALTER TABLE llx_inbox_account ADD COLUMN icon varchar(48) DEFAULT NULL;
ALTER TABLE llx_inbox_folder ADD COLUMN color varchar(7) DEFAULT NULL;
ALTER TABLE llx_inbox_folder ADD COLUMN icon varchar(48) DEFAULT NULL;
