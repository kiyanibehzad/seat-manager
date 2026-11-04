-- Adds an opt-in capability; existing groups retain their current permissions.
INSERT IGNORE INTO access_capability_codes(code) VALUES ('capacity.manage');
