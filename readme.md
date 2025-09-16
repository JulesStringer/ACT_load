# ACT load

This plugin loads pages and posts from the following sources:
+ Load Single JSON Page - JSON files downloaded from the WP REST API into subdirectories of newsite
+ Load Pages and Posts - Wordpress pages and posts from a legacy site
+ Check Pages and Posts - Checks references from content on new site,
  this runs much quicker and more interactively that Load pages and posts.
+ Migrate versioned media.
  
This plugin has been written to work with the actionclimateteignbridge.org website, but may be useful elsewhere.
The option Load Pages and Posts has been cannibalised in the creation of the final 2 options, and hasn't been tested since then.

Load Pages and Posts was written first as a batch process, which has poor user interaction, and is prone to running out of resources,
it is a source of useful functions some of which were used in the later functions. If I had time / motivation I would rewrite it in the
style of Check pages and posts, which has adopted much of its functionality. 

## Load Single JSON Page
Takes a json file downloaded from the Wordpress REST API (as used in https://actionclimateteignbridge.org/newsite and imports it to the current site, including:
            
+ Moving any images
+ Converting links to newsite and cc.actionclimateteignbridge.org/wordpress

NOTE - this option was used early in the loading development to recover a couple of pages from newsite to oldsite.
It is not expected that it will be used long term.

## Load Pages and Posts
Loads posts, pages, team members from various sites to the current site. The following are transformed in the process:

+ Images are reduced to no more than 200kb and no more than 300 X 300 pixels
+ Links internal to the site are changed so they still work (more detail later)
+ Categories are rationalise (see below)
+ Authors are mapped to users on the current site
+ Comments are copied
+ Featured media is set
+ Any post excerpt is set

## Check Posts and Pages
Produces a quick report on references src= and href= in the content of posts and pages.
+ Ignores https://actionclimateteignbridge.org/lookup_document.php
+ Ignores any link containing plugins/ACT_maps as this is a generated iframe within the plugin which manifest at runtime only
+ Detects any references with the complete home url embedded and removes this in an revised version of the link,
+ Detects any other reference containing actionclimateteignbridge.org and suggests a correction initially manual,
  classifies these by mailto, newsite, pdfs, docx, images, maps, other.
+ For mailto a suitable contact-us link is suggested.
+ For newsite a table of page lookups is being constructed, links are suggested from this.
* For pdfs these are assumed to be versioned documents, so a link via lookup_document.php is suggested.
+ For docx export to pdf is suggested then lookup_document.php.
+ For images moving to the the new site is suggested followed by linking to the new address
+ For maps these need to be added to the list for ACT_maps
+ Others are either links we can leave, or still need consideration (the number of these is dropping slowly).

## Migrate versioned media
Copies documents referenced in oldsite/act-document-list to media library of current and
creates a document with correct links and matching document.


### Load Pages and Posts - input form
The admin form has the following inputs:
+ Source Site - remote site containing source posts can be:
- ACT - actionclimateteignbridge.org/oldsite
- WW - ww.actionclimateteignbridge.org
+ Content type - post type to convert can be:
- Post - normal post
- Team - team member (post type used by the RadiusTheme Team plugin)
- Page - normal page<
+ Method - selection method for source can be: 
- All - all within data range
- URL / slug - URL or slug of a single post
+ Dates - from and to date range for current batch
+ URL - url of page to convert
+ Slug - slug of page to convert
+ Insert Pages/Posts - Checkbox normally set , which can be used for a dry run
+ Generate Report - generates a report on conversion process.

### Processing detail
#### Link transformations
Links matching patterns in the table below are transformed as follows, all other links are untouched:
|Pattern|Transformation|Reasoning|
|-------|--------------|---------|
|*/newsite/page.php/post/*|Replaced with index.php|newsite uses page.php to render json pages/posts whose slug follows|
|/newsite/page.php/page/*|https://actionclimateteignbridge.org/newsite/page.php/page/*|Pages are being rewritten so don't have a straight equivalent, better for editors to move these manually|
|/lookup_document.php*|https://actionclimateteignbridge.org/lookup_document.php*|lookup_document.php is used to look up versioned documents on ACT and TECs sites, needs to point to a directory which will have a working lookup_document.php|
|*/newsite/post.html?slug=*|*/index.php/slug|This is the old form of link used by newsite which renders posts from json files, it needs converting|
|*/wp-content/uploads|Uploaded attachment is moved and path adjusted to match||

#### Category Transformation
Categories are transformed as follows:
|Old category slug|New Category slug|
|-----------------|-----------------|
|build-environment|energy-and-built-environment|
|news|news|
|newletters|news|
|upcoming-events|news|
|carbon|carbon-cutters|
|carbon-cutters|carbon-cutters|

All other categories remain the same

#### Authors
All WW posts have been assigned to Vicky.
Any posts written by Flavio have been assigned to Vicky
Any posts written by Peta have been assigned to Scott
The original author has been retained in other cases.
Some posts have used the device 'Writes X' to indicate that Pauline entered and edited a post originating from a guest writer, who is not a user of the wordpress system. This guest writer is not currently acknowledged as author.





