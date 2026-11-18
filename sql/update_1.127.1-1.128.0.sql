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


-- The same key of a browser registered in each entity of the user (its storage is the one of the site): unique per
-- entity, the old unique index without the entity removed
ALTER TABLE llx_inbox_device ADD UNIQUE INDEX uk_inbox_device_entity_key (entity, fk_user, fingerprint);
ALTER TABLE llx_inbox_device DROP INDEX uk_inbox_device_key;
