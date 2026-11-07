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


-- 1.15.0: a mail of the junk from a known and authenticated sender (probably legitimate), or not authenticated (imitation)
ALTER TABLE llx_inbox_mail ADD COLUMN junk_hint varchar(16) DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN junk_detail varchar(255) DEFAULT NULL;
ALTER TABLE llx_inbox_mail ADD COLUMN junk_check smallint DEFAULT 0 NOT NULL;
