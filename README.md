# Roles to Taxonomy

WordPress plugin to store user roles and user levels in a taxonomy, for performance.

Having many users in a WordPress database leads to some bad performance when it comes to quering for users by role, user level, or counting users. This is perticulaoily a problem because the default WordPress admin users list table, post table and post edit screens all make these queries.

The fundamental issue with the default WordPress storage mechanism is the use of Post Meta for user levels and roles. To add insult to injury, roles are stored as a serilalized array, so any queries for users by role results in a MySQL `LIKE` query on an unindexed text column (`meta_value`).

Roles to Taxonomy registers two shadow taxonomies to associate user objects with role/user level terms. This results in a much faster lookup for  users in a given role. The term `count` field is also be used to calculate user counts in some cases.

## Performance Comparisons (2.2 million network users, 1.5 million on a single site)

|WordPress Default|Roles to Taxonomy|
|---|---|
|User List Table (all)|36 **seconds**|7 milliseconds|
|User List Table (specific role)|29 **seconds**|5 milliseconds|
|Post List Table|31 seconds|2 seconds|
|Sites List Table|59 seconds|4.3 seconds|

## Usage

When you activate the plugin, you will need to backfill the taxonomies with the existing user roles and levels. The plugin comes with a WP CLI command to perform a synchronization of existing roles.

```
wp roles-to-taxonomy sync [--verbose] [--batch-size=<number>] [--progress] [--offset=<number>] [--fast-populate] [--limit=<number>]
```

- `batch-size=x` dictactes the chunks of users to process at a time. If you have 10,000+ users, you'll want to set this to reasonable chunks (say 5,000) no not exhaust all available memory.
- `progress` will output a progress bar.
- `limit=x` will restrict the total amount of user roles synced to taxonomies.
- `offset=x` will resume updating users from a given offset number.
- `fast-populate` will enable a very fast term/taxonomy population mode. This uses many direct SQL queries so bypass WordPress process, hooks, object cache etc. This is only recommended if you do not already have any users synced to taxonomies. This should only be used when you have 100,000+ users. In testing, it is able to insert use role and level terms at a rate of 1 million users per 15 minutes.

