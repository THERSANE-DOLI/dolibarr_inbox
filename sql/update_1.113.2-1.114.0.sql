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

-- 1.114.0: date when the last copy of a mail left the mail server (removed or moved by another client, or by inbox),
-- empty again when the mail comes back; the nightly job forgets the mailrefs gone for long
ALTER TABLE llx_inbox_mailref ADD COLUMN date_gone datetime DEFAULT NULL;
ALTER TABLE llx_inbox_mailref ADD INDEX idx_inbox_mailref_gone (entity, date_gone);
