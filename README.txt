=== Bloggy till WordPress ===
Contributors: feedmeastraycat
Tags: microblogging, bloggy.se
Requires at least: 2.7.0
Tested up to: 3.0.0
Stable tag: 2.1.1

This plugin imports or posts to the Swedish microblog Bloggy.se. 
It's only available in Swedish and only useful for Bloggy-users. :)

== Description ==

**Swedish only!** Bloggy till WordPress &auml;r en liten plugin som h&auml;mtar in dina 
uppdateringar p&aring; mikrobloggtj&auml;nsten Bloggy.se och sparar dem som bloggposter 
i din WordPress-blogg. Den kan ocks&auml; skapa uppdateringar p&aring; Bloggy n&auml;r du 
skriver inl&auml;gg p&aring; din blogg. Eftersom Bloggy.se &auml;r p&aring; svenska, 
s&aring; &auml;r pluginen det med.

= Nyheter i 2.1.0 =
* Inga nya funktioner. Bara buggfixar. Om du har haft problem med pluginen s&aring; kommer
den f&ouml;rhoppningsvis fungera b&auml;ttre nu! :)

= Nyheter i 2.0.0 =
* M&ouml;jlighet att uppdatera Bloggy n&auml;r du skriver ett inl&auml;gg p&aring; din blogg.
* Fler inst&auml;llningar f&ouml;r ifall, och hur, inl&auml;gg ska h&auml;mtas in.

*Kr&auml;ver PHP 5.*

== Installation ==

Swedish only! 

1. Extrahera ZIP-filen och flytta hela mappen "bloggy-till-wordpress" med inneh&aring;ll 
   till `/wp-content/plugins/` i din WordPress-installation.
2. Aktivera pluginen under 'Plugins' (Till&auml;gg p&aring; svenska) i WordPress admin.
3. Fyll i dina inst&auml;llningar under Settings > Bloggy till Wordpress 
   (Settings heter Inst&auml;llningar p&aring; svenska).

== Files ==

* /bloggy-till-wordpress/bloggy-till-wordpress.php
* /bloggy-till-wordpress/bloggy-till-wordpress-admin.js
* /bloggy-till-wordpress/bloggy-till-wordpress-admin.css
* /bloggy-till-wordpress/README.txt

== Changelog ==

= 2.1.1 =
* Post Meta data was saved multiple times for each post. Removed the use of 
  update_post_meta and just uses add_post_meta with the $unique flag set to true.

= 2.1.0 =
* WordPress 3 compability check
* Import from Bloggy to the blog - The Bloggy post id wasn't saved correctly which
   caused multiple imports of the same post. Not sure if this has existed in 2.0.0
   all the time or if this has appeared in a WordPress update. It should be fixed 
   now anyway.
* Post to Bloggy from the blog (when creating new posts) - The Bloggy post id wasn't
   saved correctly here either. Same as the import bug.
* Install bug - The plugin database table that keeps track on which Bloggy posts 
   that has been imported was not created. Might have been a SQL bug or a WordPress
   update thing. 

= 2.0.0 =
* Added the possibility to post from your WP-blog to Bloggy