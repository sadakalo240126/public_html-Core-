#  Inventory

Catalogue
1. at a glance
2. which is good
3. the most urgent problem
4. Audit over file
4.1 Main page
4.2 Items Module
4.3 Helpers
4.4 Layout and Asset
4.5 Outside Inventory
5. Same problem with all files
6. Database plan
7. New Structure
8. Original logic, step by step
9. List of rules
10. 3 day plan
11. who can better
12. List of tests
13. the meaning of the words
Code Audit & Rebuild Guidelines
Sada Kalo Inventory: Every file's problem, good side, and enterprise means full instructions
Date:25 September 2026
Scope: public_html/InventoryIts 53 files (~13,000 lines), withCore/thedb_connect.php, route.htaccess
Method:Every file has been read the whole
Limitations:Did not run the code, nor did the live database be viewed. The table structure code has been assumed to be read. So where "guess" is written, it should be matched with the actual schema. The line numbers are from the September 24 file.
1. at a glance
The system works, and there are good habits in many places: almost all queries are prepared statements, mostly CSRF tokens in AJAX, selling prices separately. Weaknesses are mainly in three places:

Accounts of money and stock do not come from one place.Same work (return approval, price edit, category edit) written on two or three pages separately. As a result, the profit of one page does not match with another page, and in some works, the stock increases and decreases wrongly.
A few direct safety holes.The notes written by the staff can run as code on the admin's page, written in the password code of the audit log, the old source code and the config file can be read from the browser.
Every page is like your own.Each of the 24 pages has your session-check, your log function, your sidebar, your own SMS code. To change a rule, you have to touch 24 places.
11
Urgent: Money/stock mistakes or safety holes
21
High: Will trouble soon
19
Medium: speed, maintenance, incorrect information
10
Less: Cleanliness
Signs:Important High Medium Less good. . . . Every problem has an ID (egC-03), so that the work can be said by ID while dividing the work.

2. Which is good (must keep them)
safety base
Database password is outside the web folder/home/sadakalo/App/.envA.
Core/Bootstrap.php+AuthKernel: Secure cookie, one active session, block check, 20 minutes timeout.
Original Prepared Statement in PDO (EMULATE_PREPARES=false), Exception mode.
No SQL paired with user input. Where the inside is placed, there is only an int or self-made condition.
csrf tokens in almost all ajax,hash_equalsCompare with.
Helpers/AndLogs/in the folder.htaccessBrowser is closed.
correct calculation habits
The selling price, the cost, the selling price and the profit are saved separately in the selling line. Even if the price changes later, the old account remains correct.
Selling, Return, Transaction in Supplier Exchange.
Supplier Exchange and Item ListFOR UPDATELock the row with.
Rules for Staff on Servers: Cost 15 ৳ Fixed, Selling Under Purchase, Awaiting Return Approval, Sales History Just Today.
Code Structure
Items/The best code for the module project: Controller, Model, Service, View is different; Path-traversal protection; Safe JS config with JSON_HEX flag.
admin_inventory_control.phpThis is Repository + Interface, and separate Exception of Business Errors.
Layout/menu.php: One source of the menu.
Helpers (ImageHelper, StorageLocationHelper, Locksettings, Audinit) are central and fail but the main work does not stop.
Features & UX
Location Timeline (location_logs) and Audit log.
Before saving the image, a new JPEG is created with GD, thereby erasing the hidden code inside the image.
Pending → Approve Step in Supplier Exchange, SMS/Email for approval.
Mobile-First Bangla UI, PWA, Light/Dark Theme.
notification_dashboard.php: Security header, verification of image path, text with text node in JS.
3. The most urgent problem (must stop today)
id	What's going on	Where	Damage
C-01	Audit log delete password@2233Written in direct code, and the audit log can be deleted	Audit_log.php:39–43	Anyone who sees the source (C-04) will know the password; Evidence of rigging can be erased
C-02	Return Notes written by Staff, product name, invoice number sit without escape on the admin page	admin_return_history.php:177,190,191,200,202	If you write the code in the staff note, it will run in the admin's browser, it will work in the name of the admin (Stored XSS).
C-03	If canceled or pending return is deleted, the product is added to the stock and the sale is reduced, but the goods are not returned.	admin_return_history.php:45–75(Line 52)	Stocks show more, sales and profits show less
C-04	The old source code can be read as white text in the browser (.php-oldthe.php0the.php1the.php00the.sql) )	Inventory/Doc/(any.htaccessNO)	The whole logic, the name of the table, the rules everyone can see
C-05	Config file can be read from the browser including admin's phone and email	notification_config.jsonthelock.jsontheitem_edit_lock.json	leaking personal information
C-06	If you give a piece minus in POS (qty=-5) stock rises, minus sales are created	inventory_pos.php:139	Stocks and sales accounts are lost
C-07	If two sell the last piece of the same product together, the stock goes to minus (no row lock)	inventory_pos.php:129–163; Same Problemreturn_product.php:88–91theadmin_inventory_control.php:115–117	Minus stock, more returns
C-08	Inactive, "Products" and "Damage" products are also sold in POS. But on the admin page it says "POS will no longer be seen"	inventory_pos.php:63–94, 129–134; Messageadmin_inventory_control.php:725	Lost/broken goods are sold out
C-09	Changing the rate from the sales history changes the price of the line, but the total amount of the invoice does not change the profit. And the admin control page profit falls from that total	inventory_sales_history.php:71–100Versusadmin_inventory_control.php:58–62, 97–108	Two types of profit on two pages; which one is not accurately understood
C-10	No Audit of Large Changes in Money: Invoice Delete, Sell Price Edit, Stock ± , Product Edit (from Admin Control), Return Approval/Delete, Category Edit	admin_inventory_control.php:83–136theadmin_return_history.phptheinventory_sales_history.php:71theadmin_category_control.php	It is not known who has changed the account of money
C-11	Three different names for session timers:last_activitytheLAST_ACTIVITY(items),last_action_time(db_connect). r db_connect timeoutindex.phpsends to (relative), which is from Inventory/Inventory/index.phpbut there is no file in this name	Items/bootstrap.php:47–54thedb_connect.php(auto-logout part), all the old pages	If you just work on the item list, you get logged out when you enter another page; Shows the wrong page in the timeout
4. Audit over file
In each file: What is the job, what is good, what is the problem (with the line), andwhat to dothe The "what to do" section is direct work instructions for the new developer.

4.1 The main page (Inventory/route)
inventory_pos.php
634 Lines · Selling POS
Search/scan the product, saves sales, reduces stocks, writes location timelines and audits.

goodTransaction; invoice duplicate check; Buying on the server + selling below cost is prohibited (152); Save location at the time of sale; Audit log.

C-06Line 139:qtyIt is not seen if less than 1.
C-07Line 129–134: Time to fall in stockFOR UPDATEno
C-08Lines 83–94, 63–70, 129:statusAnditem_locationare not seen.
H-01Line 502–507, 613–619: Product Name/Code DirectinnerHTMLA;onclick='addToCart(${JSON.stringify(item)})'Named'If it breaks, the code can be inserted.
H-02Lines 43–49, 322, 675: The invoice number sends the browser and the browser makes +1; If sold at two counters simultaneously, the second gets an error with the same number.
H-03Line 199:rollBack()without seeing if there is a transaction; And the error message (with db) is directly to the user.
m-01Line 511: Shows staff buying+costs (business decisions, to be sure).
M-02Line 565: Loading sound from the external site (soundjay.com) every time the cart is added. Line 544: Default 8 pieces written in code.
M-03Money calculation in PHP float (156–160); A large amount of money may be wrong.
What to do:The whole logic of the saleSaleServiceTake it, the steps8.1This is given. Search and scanstatus='active' AND item_location IN ('shop','godown') AND pieces>0the The invoice number will create the server (from the database sequence table), the browser will only show. Written AlltextContentplace with,onclickThis is not the data,data-indexday
inventory_dashboard.php
634 lines · Inventory hub
Banner, Quick Menu, Stock Summary and Pending Return for Admin, Notification Bell.

goodAdmin-data only admin; JSON when the session ends in AJAX; csrf.

H-04Line 654–685: Bell every 30 secondsnotification_dashboard.phpWho calls, which pair the whole sale, product and adjust the table (H-10). Each open tab = 2 heavy queries per minute + per requestusersUpdate to the table.
M-04Line 121, 131:DATE(created_at)=CURRENT_DATE, index doesn't work. Today's product on "Add"CurrentPieces are caught, so if sold today it is reduced.
M-05Line 579–619: Unused Lightbox and FAB code. Line 689:pwa_shell.php </body>After that. Line 457: 2.8 MBbanner.jpgthe
m-06Line 354-419: Own sidebar, again 590 shared sidebar, resulting in two sidebars on one page.
What to do:Statistics will comeReportingServicefrom (8.5), which will also use all other report pages. Make a separate light endpoint for Bell, which will return only the number of "after the last view", along with a 5-minute cache. Banner is below 200 kb.
inventory.php
647 lines · Product Add
New product (code, category, piece, price, picture, location), new category for admin.

goodVerifying the server with Validator; Verify and resize image MIME; Staff cost on the server 15; Audit, location log, email.

H-05Line 183: The code comes from the browser (the "next code" when opening the page). When the two open together, the second one gets the "barcode" error.
H-06Line 157 vs 183: Verified in uppercase code, which the user has given (skf-12).
H-07Line 216 vs. 222: Save before picture, db after; If the DB fails, the picture is left. Line 236-282: If there is an error in the log/email after the commit, it shows "err" even after the product is saved.
m-07Line 81:check_duplicateAndget_upload_lockThere is no csrf. Lines 61–68: Own copy of upload lock read (LockSettingsto stay).
M-08 ValidatorIts rules^[A-Z]{2,5}-\d{2,5}$: Scanning barcode (EAN-13) of the store does not save.
What to do: ProductService::create()the8.2see from the sequence on the code server; A different flag if scanned code. Move to the original folder if the image is in temp, DB commits. The next act of commit (log, email)try/catchA, or in the queue.
CATEGORY_MANGE.php
1122 Lines · Category Panel
Category card, each ad/sell/stock, clicking product list; Admin can change the name and turn it on/off.

H-08Line 691, 759:addslashes(htmlspecialchars())in reverse order; Named'If the edit button does not work, the code can be inserted.
H-09Line 25–29: per requestALTER TABLEtry
H-11Line 59-65: Changing the name does not change the image folder and product name (product name = category name).admin_category_control.phpThe same work is written separately.
M-09Lines 162–165, 102–106: Sold pairsCurrentby category. If the product category is changed, the old sales also go to the new category (the category name saved in the sales line is not used).
m-10Line 1131–1174: Panel search and CSV only loaded 20 lines; In CSV"No escape. Line 39:ob_start()withoutob_clean()the Line 130: Error message to the user. Line 235: Useless CSS.
What to do:This page is anymoreadmin_category_control.phpa combined oneCategoriesmodule, aCategoryService::rename()(8.6). Category wise sales accounts to be saved in the line of salecategory_idwith (new column). Search and CSV servers on the entire data.
admin_category_control.php
301 lines · Category Control (Admin)
H-12Line 32:$_POST['status']in db without verification; Line 39:idwithout cast.
H-08Line 190:addslashes(htmlspecialchars())the
H-09Line 20–21: At runtimeALTER TABLEthe Line 42: Error message to the user.
M-11Line 11:$_SESSION['role']If not, notice. Line 73:html5-qrcodewithout version. Line 110:unsold_inventory.phpno
What to do: category_mange.phpIntegrate with it (above). Status Onlyactive|inactiveWhitelist. Audit log.
admin_inventory_control.php
784 lines · Admin Control
Profit (from the beginning/month/today), P&L ledger, invoice delete, selling price edit, product edit, stock ±, return approval, product launch/off, monthly archive.

goodrepository + interface; BusinessLogicException; Transaction; Admin Guard (including AJAX); In the price edit, the lowest price rule and the total amount are calculated again.

C-10Lines 83–136: No work audit;updateProduct(109) does not have an Edit Log, which is edited from the Item List.
C-07Line 115–117: No lock before stock reduction.
H-13Line 122–128: Location Timeline on Return Approval (returned) is not written;admin_return_history.phpThis is another copy of the same work.
H-14Line 109-111: Price can be minus, no transaction. Lines 83–96: Invoice delete does not have location logs.
H-10Line 278: Separate queries for each laser date (N+1) +DATE()the Line 163-171: The entire statistic is also calculated in every AJAX request.
H-15Line 295, 394:onclick="openImageModal('{$img}')"; Name of folder'If it breaks (ImageHelper)'does not exclude).
M-12Laser pages are divided along the line, showing the date, so a total of a date is divided into two pages and shows wrong. Line 725: "Not seen in POS" message is not true (C-08).
What to do:Break down:Reports(profit, laser),Sales(Invoice Delete/Price Edit:SaleService::void()theSaleService::repriceLine()),Stock(StockService::adjust()),Returns(ReturnService::approve()). Every job is from one service, same rule, with audit.
admin_return_history.php
244 lines · Return log (Admin)
C-02Line 177, 190, 191, 200: Except for Escape; Line 202:showNote('<?php echo addslashes($row['note']) ?>'). . . . Notes come from the form of staff.
C-03Line 52:status !== 'approved'Add to stock; Rejected and read in it.
H-03Line 37, 74:rollBack()Without check, error message to the user. Line 41: Is it cancelled (rowCount) are not seen.
M-13Line 79: All returns are loaded together, no pagination. Line 211: Allowed returns are also deleted buttons.
What to do:Not "delete", but two separate tasks: (a) return cancellation = status change, stock will not change; (b) "Incorrect sale canceled" =SaleService::voidLine(), which clearly returns stock and reduces sales. Don't delete any records, change the status. Escape all outputs.
return_product.php
343 lines · Customer return
goodaccount on the refund server; Again the maximum piece check inside the transaction; Awaiting Staff Return Approval.

C-07Line 88–91: No lock; Taking the return of the same invoice together can be more than the maximum.
H-13Line 101-111: Location Log Only admin takes direct return; The staff is not logged when approved.
M-14Lines 79-81: Invoice only holds the first if the same product is in two lines. Line 284–285:SKF-Prefix force. Line 9: Log goes../../Logs(all other pages../Logs).
What to do: ReturnService::request()Andapprove()the8.3. . . . Returns will be tied to the line of saleidIn this, not with invoice+code.
inventory_sales_history.php
794 Lines · Sales History
goodStaff just watches today; Profit is just admin; Net account excluding returns; Date format verification.

C-09Line 91: The price of the line changes,inventory_salesIts total does not change; There is no minimum price rule; No audit.
H-03Line 96:rollBack()without check.
H-15Line 329, 335, 373:addslashesin the code attributehtmlspecialcharswithout
M-04Line 144, 150, 183:DATE(); Line 178: Separate sub-queries per line; Line 132: Custom range limit is 10000 days.
What to do:Delete price editSaleService::repriceLine()Call (same with admin control). report will comeReportingServicefrom Maximum range of 92 days.
daily_activity.php
327 lines · Today's Activity (Admin)
H-15Line 39:onclick="si('{$p}')"the
M-04Lines 27–35: 7 differentDATE()query; All lists are without pagination; Just today, there is no picking date.
L-01Line 37:ifDefine the function inside; Line 54: Without the money format (৳{$bc}).
What to do: ReportingService::dailyActivity(date), all numbers in one query; picking dates; 50 per part.
Notification_Dashboard.php
962 Lines · Notification Center
goodThe most caring old page of the project: security headers,safeImagePath()thejsonExit(), text node in js, length limit.

H-10Line 265–331: Three full tablesUNION ALL, out of date conditions; Read the whole table every time. Line 369-377: Three more counts.
M-15Line 175: The rule of the picture path means only English letters;uploads/ফরমাল ⁷⁵প্যান্ট/…It does not show Bengali folder images like this.
M-04The "Add" notification shows the product's current piece, not the added piece.
What to do:Aactivity_feedtable (or stock movement laser,6), where a row will be added at the time of writing each event; Feed is just from that table,created_atin the index. Path verification will accept unicode characters, but..And the scheme will be blocked.
Notification_Settings.php
444 lines · Notification settings
H-16Line 63–127: Own.envReading, SMS and email codes. Exactly the same code in two other files (supplier_exchange.phpthesupplier_return_approval.php).
C-05Config is saved in the web folder's json file.
M-16Line 168: Test SMS is on any number (admin but no cost risk, no rate-limit).
What to do: NotificationService(sms + email) in a class; config DB table or outside the web folder; Test SMS is only saved in admin number.
settings.php
340 lines · Admin Settings (Lock)
good ob_starttheneverReturn type, just admin, csrf,LockSettingsUsage, Audit.

C-05Lock files can be read in the web folder.
L-02Another copy of the same lock on the items page (toggle_edit_lock).
What to do:in the settings db table (app_settings),SettingsServicewith Delete the toggle of items.
out_of_stock.php
196 lines · Stock exhausted
M-17Lines 55 and 122: The number of these pages (maximum 10) shows as "total" above, not the actual total ($totalItems).
M-04Line 46: Separate sub-queries of "last sale" per row; Inactive products also show.
What to do: $totalItemsshow;last_sold_atPut the column in the product (updated at the time of sale);status='active'filter.
product_edit_history.php
136 lines · Edit logs
H-17Line 7: No timeout check;roleIf not, notice.
H-14This table only writes item list; Editing the product from admin control does not log.
L-03Last 200, no pagination/search.
What to do:One way to change the productProductService::update(), which always writes history. This page will be filtered by an audit module.
Supplier_exchange.php
1289 Line · Supplier Exchange
goodin old goodsFOR UPDATE(421); Pending all if not admin; Audit (550–576); SMS/email when pending.

H-18Line 449–453: The purchase price of the entire stock on the “same product” exchange changes to the new price; The purchase price of the previous pieces also changes, so their profit is wrong later.
H-07Lines 448, 473, 489, 509: Save inside the picture transaction; If the rollback remains, the picture remains. Line 83: Pictures gouploads/In root, not in the Category folder.
H-02Line 63–80: Exchange No. and the new product code is the last row of +1 (the same number if two together).
H-19Line 277-285: Email product name, supplier name, who sent without escape. Line 1359–1369: HistoryinnerHTMLWithout this escape.
H-16Line 123–231: Copy of SMS/Email/.env code.
M-18Line 474: New Productsitem_locationNo, no location log. Line 457 Ainventory_adjustmentsThisuser_id, statuscolumn, butadmin_inventory_control.php:120A is not; Two concepts of two files about the structure of the table.
M-19RollmanagerthestaffOnly here are in the report; If the rest of the page is not admin, "User".
What to do: SupplierExchangeService(8.4). Calculate the average purchase price in the "same product" or keep it as a new lot. Move the image to temp, after commit.
Supplier_exchange_report.php
561 lines · Exchange report
H-20Line 189–200: As is the name/note of the supplier in CSV;=the+the-the@When it starts with Excel, it runs as a formula (CSV injection).
m-01Line 101-131, 170: Staff/Manager can check the price and lower the CSV.
L-04Line 541, 596:innerHTMLThis should be verified what escape is taking place.
What to do:In each cell of the CSV, when the four symbols begin with'sit down The price is bought only for the admin.
SUPPLIER_RETURN_APPROVAL.php
869 lines · Exchange approval
goodwith two tablesFOR UPDATE(164–173); Just from Pending; Audit.

H-18Line 217–223: The purchase price changes in full stock (as above).
H-21Lines 324–352: There is no transaction in cancellation; Adjustment deleted notesLIKEcombined with (338); Pending images are not deleted.
H-19Line 927–940: HistoryinnerHTMLWithout this escape.
H-16Line 63–141: Third copy of SMS/email; Line 391–409: Second copy of notification settings.
What to do: SupplierExchangeService::approve()/cancel(); Adjustment will be tiedexchange_idIn columns, texts are not matched.
AUDIT_LOG.php
164 lines · View/delete audit logs
C-01Line 39: In the password code; Lines 36–49: Audit logs are deleted, and there is no record to delete.
H-17Line 21–24: No timeout; If you don't have a loginindex.php(relative, not in Inventory). Line 103, 115:dashboard.php(relative, broken).
M-04Line 56:DATE(), all together, there is no pagination. Line 138:addslashesthe
What to do:Deleting feature is completely excluded. Audit logs are just added (append-only); DB user will not have delete rights in this table. The old log archive will be after the specified period, with the script, the archive will also have a record.
invantory_items.php · Inventory_bottom_nav.php
28 + 16 lines
goodBoth are clear: the first is the entry of the Items module, the second loads the shared nave.

L-05spelling the name (Invantory). entries in the new structure will be/Inventory/Items/the
4.2Items/Module (9 files)
items/*
Bootstrap, Controller, Model, Service, 3 View, CSS, JS
goodUse this as a sample of the new structure:FOR UPDATEWith Transaction (ItemModel), edit log in the same transaction,resolveUpload()with onlyuploads/Move/delete file inside, revert if image is failed,JS_FLAGSWith secure onclick, user-exception is different.

C-11 bootstrap.php:47–54: timer keyLAST_ACTIVITY, the restlast_activity. . . own session anddb_connect.php, not Core.
H-09 ItemMediaService::ensureEventEnum(): at runtimeALTER TABLEthe
m-06Reading/Writing LockItemMediaServiceAndHelpers/LockSettingsin two places;folderName() ImageHelperCopy of.
m-01 item_card.php: The staff also shows "buying" (buying + cost).
L-06 items.js:fetchafterwardsres.okare not seen;updateLocationAny login user can "get/damage" the product (must be sure if the rule is intentional).
What to do:Take Core on Bootstrap; LockSettingsServicefrom; Enum changes in migration; Show the purchase price roll.
4.3Helpers/(10 files)
File	good	Problem	what to do
AuditInit.php	Even if there is no audit, the main work goes on	L-07Finding Folder Up Up (6 Steps) Unsure	Directly with Composer AutoloadAuditService
CategoryFolderHelper.php	Secure name, does not merge on rename	H-11 rename()is not used anywhere	CategoryService::rename()Call from, and update the picture path as well
EmailHelper.php	Escape inside email	M-16Written in the recipient's email code (line 30), separate with the notification config;mail()in direct request	NotificationServiceMatch this; cue
ImageHelper.php	900px Resize, JPEG 82%, Name Clear	H-15Name of folder' &remains;L-08Check and write the same name in separate steps (race)	Folder Name = Categoryid(e.g.uploads/c12/), not a name
LocationAuditHelper.php	Recycling Audit Table	m-06 renderLiveView()Posts every 5 seconds without CSRF (will stop on CSRF); is not used anywhere	Delete or match the movement laser
LockSettings.php	Lock in one place, read-back verification	C-05in the file web folder	app_settingsTable
StorageLocationHelper.php	All the rules of location in one place; The main work goes on even if the log fails	M-18 updateStatus()The old condition falls without lock;renderField()Inside writes CSS/JS; Fallback color does not match other file (godown#ea580cVersus#c2410c) )	Color is the same place (DB); View code template
Validator.php	Verifying form in one place	M-08Code rules are very strict;sanitizeInput('text')HTML-encodes in the input (Wrong Place: Escape will be in the output)	Request/DTO class; Escape is just in the template
sidebar.php	—	L-09Not used anywhere, broken links (Inventory_Items.phptheunsold_inventory.php) )	Delete
.htaccesstheHelpers_Logic_Analysis.txt	The folder is closed from the browser; Good written explanation of logic	—	Take the explanation file in the new documentation
4.4 Layout, Theme, Config & Asset
File	Situation	what to do
Layout/menu.phpthenav.phpthecss/nav.cssthejs/nav.js	goodOne source of the menu, escape, highlight, ownskn-css.M-11Broken Link:unsold_inventory.php(No file),/Inventory/Audit/(there are audits/Audit/at)	keep; fix the link; Each page will only use this
theme.css(819 lines),theme-toggle.js	goodtoken based theme.m-06Each page is different<style>and inline style; The default of the theme is not the same on all pages	a design system; The css of the page is in a separate file
notification_config.jsonthelock.jsontheitem_edit_lock.json	C-05Can be read from browser	today.htaccessoff with; later to db
banner.jpg(2.8 MB),logo.png	M-05Banners are loaded on many pages; slow on mobile	Webp, below 200 KB
Doc/(10 files)	C-04Old sources and SQL can be read;inventory_dashboard_v2.phpCan also be run	Remove from Web Folder Today (Keep in Git)
uploads/	M-15folder name with bangla/symbols (Ⓜ️নেট ডেনিমthe🟡সাদা-কালারিং🟡saj; prevent uploading php file.htaccessare not	uploads/.htaccessstop running php at; new picsuploads/c{id}/
4.5 Outside Inventory, on which Inventory depends
File	Situation
Core/Bootstrap.phptheAuthKernel.phptheAuth.phptheCsrf.php	goodThese are the basis of the new structure.L-10csrf fails to give HTML pages, not JSON for AJAX;Auth.php ResponseNot sure if the class mail is loaded
db_connect.php	C-11Timeout and Session-Replaceindex.phprelative redirect;H-04at every requestusersA. Update + Select. This is what old pages run
Route.htaccess	M-11 RewriteCond %{Dps} -dtypo (useless condition); Any incorrect link shows the homepage, so the broken link is not detected
5. Same problem with all files
Topic	What is now	which will be
Session, Login, CSRF	24 copies on 24 pages; What are three timers?	OnlyCore/Bootstrap.php+ Middleware
Roll	adminthemanagerthestafftheusertheviewer, randomly; somebodystrtolowerdoes, no one does	roll-permission table;can('sale.reprice')kind of check
The log	logSystemError12 copies, three types of paths	ALogger(PSR-3), daily file, rotation
JSON Reply	{status,message}the{error}the{s,m}, empty array	Always{ok, data, error:{code,message}}
Making HTML	html pair in php string (100+ lines in admin control), inline style	Template, Auto-escape
JS data placement	addslashestheonclick="f('…')"theinnerHTMLThis raw data	data-*Attribute +textContent; Configjson_encode+ JSON_HEX
cdn	Font Awesome 6.0 and 6.5, without HTML5-QRcode version, sweetert2@11 floating	A specific version, if possible, on your own server
Date	in phpAsia/Dhaka, server time in MySQL;DATE(col)=?	in connection withSET time_zone='+06:00';col >= ? AND col < ?
Money	php float	money integer orDECIMAL(12,2)+Moneyclass
schema shift	within the requestALTER TABLE(3 places)	Migration Files
same work many times	Return Authorization ×2, Price Edit ×2, Category Edit ×2, SMS ×3, Lock ×2	Every job is a service method
Attempt	are not	Test of each service; Money account test is mandatory
Version Control, Staging	Caught not (project git repo)	git, staging server, deploy script, rollback
6. Database plan
Which one is certain, which one is guessing:The column "Found in the Code" contains only columns that are in the codeINSERT/SELECTThis was found directly (September 25,F:\pro\Inventorymatch from). The original structure of the table, the data type, index and the rest of the columns are not seen, because the folder does not have any files in the entire schema. Everything in the "What to Change" columnProposal. . . Before the start of workmysqldump --no-dataMatch the original schema with it.
Table	What was found in the code	What to change (proposal)
inventory
Commodity	product_code, category_id, name, image_path, pieces, buy_price, cost, cash_sell, added_by(supplier_exchange.php:474), is read anymoreitem_location, status, created_atthe 17 files touch this table.	product_codeunique; PriceDECIMAL(12,2);piecesThisCHECK (pieces >= 0); Index(status, item_location)the(category_id)the(created_at); new columnlast_sold_at
inventory_sales
Invoice Header	invoice_no, customer_name, total_pieces, total_sell_amount, total_profit, sold_bythecreated_at	invoice_nounique; Index(created_at)the(sold_by); new columnstatus(active/void)
inventory_sale_items
Invoice's line	sale_id, product_code, category_name, buy_price, cost, sell_price, profit, pieces, item_location(inventory_pos.php:124). are paired with the productproduct_codewith;product_idOrcategory_idno	new columnproduct_idfk rcategory_idSnapshot (backfill in old row); Index(sale_id)the(product_id)
inventory_returns
Customer Return	invoice_no, product_code, return_pieces, refund_amount, return_profit, note, returned_by, statusthe There is no id with the line of sale;approved_by/approved_athe is not	new columnsale_item_idfk,approved_bytheapproved_at; Index(status, created_at)
inventory_adjustments
Stock ±	Two types of Insert (M-18):admin_inventory_control.php:120inscribedproduct_code, adjustment_type, pieces, note, adjusted_by;supplier_exchange.phpAndsupplier_return_approval.phpwith ituser_id, statusHe writes.	Fix a structure by looking at the original column; new columnexchange_idFK
supplier_exchanges	exchange_no, old_product_code, old_product_name, returned_pieces, old_buy_price, exchange_type, supplier_name, status, new_product_code, new_product_name, received_pieces, new_buy_price, note, exchanged_by, pending_payload(supplier_exchange.php:530) )	exchange_nounique; Index(status)
categories	name, statusthestatusThe column is inside the requestALTER TABLE categories ADD COLUMN status ENUM(…)is added with (H-09).	nameunique; Status column migration
location_logsthelocation_colors	location_logs:product_code, event_type, from_location, to_location, pieces, unit_price, invoice_no, note, done_by, done_by_name;event_typeenum at runtimeMODIFYislocation_colors:location_key, label_bn, color_hex, bg_hexthe	Index(product_code, created_at); enum in migration
product_edit_historytheaudit_logs	product_edit_history:product_code, changes_details, changed_bytheaudit_logsAudit class is written outside the Inventory, so the column is not seen here;Audit_log.phpOnlyusername, created_atSearch and delete it.	Just Insert Rights; Index(created_at); The rest of the index looks at the actual column of the audit table
Another thing to match: Doc/পণ্য পাইনি.sqlThisproductsA table is read in the name of (stock, purchase_price, selling_priceincluding), but none of the php files in the inventory use this table. Whether it is the table of other parts of the site (like online stores), andinventoryWhether it needs to match its stock with the table, it should be confirmed to the owner.

three new tables
stock_movements(stock book):Each stock change is a row:product_id, type (purchase|sale|sale_void|return|adjust_in|adjust_out|exchange_out|exchange_in), qty (+/-), unit_cost, ref_type, ref_id, location, user_id, created_attheinventory.piecesThere will be, but the same transaction will be updated with the ledger. A script will match at nightpieces = SUM(qty)the This will be the source of notification feed, daily activity and timeline.
sequences: name, next_valuethe Invoice, Product Code, Exchange No. from here,SELECT … FOR UPDATE+UPDATEwith The two will never get the same number together.
app_settings:Upload lock, staff edit lock, notification phone/email, default piece of POS, staff cost (15).
The order of migration
Full backup (schema + data), restore in staging.
Find Duplicate: Sameproduct_code, the sameinvoice_no. . . . Fix before giving unique.
Adding new columns/tables (old code will continue).
Backfill:sale_items.product_idthereturns.sale_item_idthestock_movementsA. The current stock as "opening balance".
Unique and Index.
Stop using old columns when new code is launched; Delete after a week.
7. New Structure (Enterprise Value)
Decision:Moving the entire Laravel in 3 days is risky. So in the first step, in the current PHP 8.3, a clean layer will be made with Composer + Psr-4. The code will be outside the web folder. Laravel is in the second step, because these layers can be placed directly there.

/home/sadakalo/
├── App/                          ← ওয়েব থেকে দেখা যায় না
│   ├── .env                      (আগে থেকেই আছে)
│   ├── composer.json             (PSR-4: "Sk\\" → src/)
│   ├── src/
│   │   ├── Shared/               Auth, Csrf, Http (Request/JsonResponse), View, Logger,
│   │   │                         Money, Clock, Db (PDO factory), Audit, Notification, Settings
│   │   └── Inventory/
│   │       ├── Domain/           Product, Sale, SaleLine, ReturnRequest, StockMovement …
│   │       ├── Application/      SaleService, ProductService, ReturnService,
│   │       │                     StockService, SupplierExchangeService, CategoryService,
│   │       │                     ReportingService
│   │       ├── Infrastructure/   Pdo*Repository (সব SQL এখানে)
│   │       └── Http/             Controllers/, Requests/ (যাচাই), routes.php
│   ├── templates/inventory/      layout, partials, প্রতিটা পেজ (অটো-এস্কেপ)
│   ├── migrations/               0001_… .sql
│   └── tests/                    Unit/, Feature/ (স্টেজিং DB কপিতে)
│
└── public_html/
    └── Inventory/
        ├── index.php             ← একটাই এন্ট্রি (front controller), বাকি সব রাউটার দিয়ে
        ├── .htaccess             ← সব রিকোয়েস্ট index.php তে; json/md/sql/old ফাইল বন্ধ
        ├── assets/               শুধু css/js/ছবি (ভার্সনসহ)
        └── uploads/              .htaccess: PHP চালানো বন্ধ
browser

inventory/index.php

Core Bootstrap: Session, DB, Timeout

Middleware: Login, Roll, CSRF

Controller: Input Verification

Service: Rules + Transaction

Repository: SQL

mysql

Audit + stock book

Notification Q

Template: Escape HTML/JSON

level responsibility
Layer	will	will not do
Controller	Reading Request, Verifying with Request Class, Calling Service, Returning Templates/JSON	SQL, money account,$_SESSIONDirectly
service	Business rules, transactions, locks, audits, accounts, events	html,$_POSTtheecho
repository	Prepared sql, resulting in the domain object	Rules, permission checks
Template	Just shown, all outpute()With	query, account
js	ui, ajax,textContent	The final decision on the price/permission (that is from the server)
8. Original logic, step by step
Every service method will run in the same order. The order cannot be changed.

8.1SaleService::create(lines, customerName, userId)
Verification (before transaction): at least one line; per lineqtyInteger 1–9999;pricemoney, 0 or more; Adds one line when the same product arrives twice.
BEGIN.
Products Lock:SELECT … FROM inventory WHERE id IN (…) FOR UPDATE(id in order, so as not to be deadlock).
per product:status='active'theitem_location IN ('shop','godown')thepieces >= qty; If not, business error with Bengali message.
Lowest price = buy + cost; User'ssale.below_costPrice ≥ minimum if not allowed.
Invoice Numbersequencesfrom
Insert header → Insert line (price, cost, profit, category, location snapshot).
UPDATE inventory SET pieces = pieces - ? WHERE id = ? AND pieces >= ?;rowCount() !== 1If there is an error.
stock_movementsThissaletheqty = -nthe
Total of header = sum of line (recalculate again with sql, not php sum).
Audit queue (in same transaction).
COMMIT. If there is an error in any step, rollback will not be saved.
After the commit: Notification queue. If you fail here, the sale will be successful.
In the same rule:voidSale()(invoice cancel = statusvoid, stock return, in accountsale_void; If there is a return prohibited);repriceLine()(permission check, lowest price, line + header total together, old/new in audit).

8.2ProductService::create(input, image, userId)
Verification: Category enabled; Peace 1–99999; The price is 0 or more; Locationshop|godownthe
Cost: From settings (now 15) if not allowed, ignore the form value.
Selling ≥ Buy + Cost if not allowed.
If the image is: upload lock + source verification; mime; New JPEG with GD;tempin the folder.
BEGIN → Code (if not scannedsequencesFrom) → Duplicate Check → Insert → In the ledgerpurchase→ Audit → Commit.
temp picture if commituploads/c{category_id}/{code}.jpgMove and update paths; Delete temp if failed.
After the commit in the email queue.
8.3ReturnService
request(saleItemId, qty, note, userId): Begin → Sales LineFOR UPDATE→ Return (Pending + Approved) to find Max → Qty ≤ Max → Refund = Line Price × Qty, Profit-cut = Profit × Qty → Insert Statuspending(Dirty if allowedapprove()Steps of) → Commit.
approve(returnId, adminId): Begin → ReturnsFOR UPDATE WHERE status='pending'→ ProductsFOR UPDATE→ In stock + Qty →return→ Statusapproved, K/When → Audit → Commit.
reject(): Just status, don't touch stock.No delete.
8.4SupplierExchangeService
submit(): Verification → Begin → Old ProductsFOR UPDATE→ NumbersequencesPending (Payload JSON) if not allowed from →apply()→ Picture temp → Commit → Move Image → Notification Q.
apply()(in the same transaction): Returned in piece bookexchange_out(unit_cost= old buy price); New Pieceexchange_inthe New Purchase Price if "Same Product"Weight-wise average:(বাকি পিস × পুরনো দাম + নতুন পিস × নতুন দাম) ÷ মোট পিসthe
cancel(): Begin → Status → Associated with (exchange_id) Cancel Pending Adjustment → Commit → Delete Pending Image.
8.5ReportingService(the only source of all numbers)
Profit =SUM(line.profit × line.qty)(voidexcluding invoices) −SUM(approved return.profit_deduction)the headertotal_profitJust showing cache, not the source of the calculation.
Price of stock =SUM(pieces × (buy + cost)), onlystatus='active'Andshop|godownthe "Not" and "Damage" are in separate lines.
"Today Add" = Today in the ledgerpurchase + adjust_in + exchange_inthe
All Date Conditions:created_at >= :from AND created_at < :tothe
Dashboard, Admin Control, Sales History, Daily Activity, Category: Everyone will call these methods.
8.6CategoryService::rename(id, newName)
The name is not empty, in 100 characters, does not match other categories.
Begin → Category Update → Product Names = Old Category Names, Their Names are also Changed → Audit → Commit.
picture folderidIf you don't have to remove anything.
9. List of rules: what to do, what not to do
Whether the developer or the tool works, this list is part of the contract. Each PR review holds this list.

working method
Cannot do:Changing what was not asked for. List written before each task: Which file is new, which line of file will change. The owner started saying "yes".
Cannot do:Edit directly on the live server. All work on staging, in git.
must do:Each change is associated with a different PR, small, an ID (eg C-06).
must do:Backup and rollback steps are written before each diploma.
must do:Finding and fixing the link/redirect of that name in the entire project before deleting the old file.
Cannot do:Saying "tested", if not seen in continuing.
Security
must do:Escape to all output templates; js onlytextContent; the datadata-*A.
Cannot do: addslashesSetting data to HTML/JS;onclick="f('<?php … ?>')"the
Cannot do:Password, API key, phone, email code or file in the web folder.
must do:CSRF (Middleware) on each post, check permission (on server).
Cannot do:Browser sent price, cost, invoice number, roll to be true.
must do:General message to user, detailed error is just in the log.
Cannot do:Audit logs or financial records deleted. Cancel means change of status.
must do: uploads/stop running php at;.json .md .sql .old .bak .txtClosed in the browser.
must do:CSV Exports= + - @Starting with the front of the house'the
money and stock
must do:Every work that changes the stock or money in a transaction, the corresponding queueFOR UPDATEthe
must do:Reducing Stocks… WHERE pieces >= ?with, androwCount()verification.
must do:Every stock changestock_movementsA, in the same transaction.
Cannot do:Calculation of money with float.Money(int for money) or SQLDECIMALthe
Cannot do:The same account is written in two places. Profit, stock price onlyReportingServicethe
must do:Number (invoice, code, exchange) just on the serversequencesfrom
must do:After committing work outside the transaction (file, email, sms); Failure to do so will lead to success.
Cannot do:Save files inside the transaction,ALTER TABLE, call outside API.
Code and database
must do: declare(strict_types=1), typed property/return, psr-12 format, phpstan level 6+.
must do:SQL is just parameter, always in repository.
Cannot do:Schema change within the request. just migration.
Cannot do: DATE(column) = ?. . . Range condition.
must do:The list means pagination on the server (maximum 100 rows).
must do:Test of each service method; The value of the boundary in the Test of Tucker calculations (0, 1 pcs, equal to stock, more than stock, minus).
Cannot do:Copy of the same function (log, sms, lock, folder name). A class, everyone will call it.
ui
must do:Each page will use shared layout (Top bar, sidebar, bottom nave); Not own sidebar.
must do:links onlymenu.phpAnd from the name of the route; Not handwritten relative links.
Cannot do: alert()/confirm()important confirmation with. own modal.
must do:Certain versions of CDN; Not sound/picture from outside sites.
must do:Roll-wise: Buy price, profit only to those who have permission.
10. 3 day plan
Straight talk:Stopping all emergency and high risks in 3 days, setting up new structures, and starting 5 main tasks (selling, product add, return, supply exchange, report) in a new structure with a test can be introduced in a new structure, if a group of 10-14 people work in parallel and one lead is all matched. The rest of the pages will actually take another 1-2 weeks to bring the same value. Whoever promises to do "everything" in less than this, he will either skip the test or say it wrong.
Before the start (day 0, 2–3 hours)
cPanel, ftp/ssh, db access; Staging subdomain with copies of live DB.
The whole code is in git repo; THIS REPORTdocs/A.
3 days live is not a new feature (just hotfix).
Parallel team (14 work clauses)
Genre	Work	id	rely on which
W0 Lead/Architect	Rules, Interfaces, All PR Reviews & Merges, Contact the Owner	All	—
W1 emergency safety	Doc/move,.htaccess(json/md/sql/old),uploads/php off, audit deletion off, XSS (C-02, H-08, H-15, H-19)	C-01,02,04,05	—
W2 Database	Schema Dump, Duplicate, Migration, Index,stock_movementsthesequencestheapp_settings, TIME_ZONE	Part 6	Day 0
W3 Core Platform	Composer, Router, Request/JsonResponse, Middleware (Login, Roll, Csrf), Logger, Money, DB Factory, Error Handler	C-11, Part 5	—
W4 Shared UI	Layouts, templatese(), Component (Modal, Table, Pager), Asset Version	M-05, M-06	W3 (day 1 noon)
W5 Sell	Salesservice (create, void, repriceline), POS pages	C-06,07,08,09, H-01,02	W2, W3
W6 Return	ReturnService, Return & Return-Log Pages	C-03, H-13	W2, W3
W7 Products	ProductService (Create, Update, ChangeCategory, Image), Product Add + Item List	H-05,06,07, M-08	W2, W3
W8 Category	CategoryService, merge two category pages	H-08,09,11,12	W3
W9 Supplier	SupplierExchangeService, Exchange + Authorization + Report	H-18,20,21	W2, W3, W11
W10 Report	ReportingService, Dashboard, Admin Control, Sales History, Daily, Stock End, Notification Feed	H-04,10, M-04,12,17	W2
W11 Notification	NotificationService (Sms, Email), Q Table + cron, Settings Pages	H-16, M-16	W3
W12 Audit	AuditService (Append-Only), Edit Log and Audit Pages	C-10	W3
W13 QA	Test case writing (from day 1), old vs new number matching script, manual checklist	12th part	—
W14 DevOps	CI (Phpstan, PphpUnit), Deploy & Rollback Script, Backup	—	Day 0
Day 1: Safety and the Insight
Morning: W1's hotfix live (in the afternoon). C-01…C-05 off.
W2: in migration staging; W3: Core on; W4: Layout.
W5–W12: Test Writing Current Behavior, Service Interface, W0 Approval.
At the end of the day: Staging a new structure with an empty page login, csrf, layout.
Day 2: Basics
W5–W12 Service + Pages, not merged if Test is not passed.
W13: Matching results (profits, stocks, invoices) by doing the same in old code and new code in the same staging data.
At the end of the day: Selling → Returns → Exchanges → Reports, full cycle staging.
Day 3: Matching, Owner Test, Live
Morning: bug fix; Finding links (whether the old name is anywhere).
Afternoon: The owner will check the list of part 12 in the staging and continue it himself.
Afternoon/night (in low crowd): Backup → Migration → Deploy → Smoke Test → 2 hours glance. Rollback if there is a problem.
Leaving the old page for a week (just reading), then deleting.
means "has been done"
11 of the 3rd part has a test for each emergency problem, and a test pass.
Staging is the same data as the old and new profits (if there is a difference, such as writing, such as the correction of C-09).
pieces = SUM(stock_movements.qty)in all products.
PHPStan and PHPUnit CI are green.
Rollback once in staging.
11. Who can do better, and how
The code in this audit has just been read; Not seen running on the server or database. The team that will do the rebuild work must have two things: the opportunity to run on the staging server and the DB copy, and the order not to change a single line without the owner's written approval.

what kind of team would you like
Role	Number	What you need to know
Tech Lead / Architect	1	php 8, 7+ years in Laravel or Symfony; POS or accounting system has previously been made
Senior PHP developer	6–8	Transaction,FOR UPDATE, phpunit, psr-4; Everyone is a clause (W5–W12)
Database (MySQL) Expert	1	Migration, Index, Live Data Securely Changed
Frontend	1–2	Bangla Mobile UI, Accessibility, XSS-Safe JS
qa	1–2	Test case of accounts, matching numbers
Security Reviewer	1 (part time)	OWASP TOP 10; Review on Day 1 and Day 3
where to look for
Software Company of Bangladesh: Those who make retail/pos/erp from BASIS member list. In one city, time zone one, Bengali can be spoken.
International Freelance Marketplace (eg Upwork, TopTal): Verified senior developer, holding bells or milestones.
Check the following before taking it from any source. Not by name or rating.
Selection Test (paid trial, 4–8 hours)
Give to the candidate: This report,inventory_pos.php, no longer schema (not data).
Work: C-06, C-07, C-08 Fixed, Tests.
See: (a) whether the line will change before the start of the work is given; (b)FOR UPDATEAndWHERE pieces >= ?Did you give? (c) whether the two wrote a test of sale together; (d) Whether any other file has been touched. If the last one is left out.
What to write in the contract
Code, git repo and all access to you; Password change after work.
Any changes to Live are not without your written approval; Backup before each deploy.
Demo on staging every evening.
Part of the Agreement: This report is the "fixed" criteria and the 9th part rules.
30 Day Warranty: Due to this work, it is free of cost if the bug is bugged.
Handover: ReadMe in Bengali, Logic, Deploy and Rollback Steps for Each Module.
With any other AI tool
Allow the tool to run code and test on staging server. Any tool will be wrong if you can't continue.
At the beginning of each task, enter the 9th part of this report, and say: "Just this ID, first show the file list".
an ID at a time; The result is whether you or a person sees the reviewer and then the next one.
12. List of exams (before going live)
Work	what do	which should be
Sale	5 pcs products sold in 5 pieces together in two phones	One is successful, the other is "not enough in stock"; Stock 0, not minus
Sale	Peace house-5(Send via Browser Console)	blocks the server
Sale	Inactive, "Gene", "Damage" Product Search/Scan	doesn't come
Sale	Price below purchase as staff	restrained
Price Edit	Sales history and admin control from two places	the same rule; The total and all reports of the invoice match the profit; old/new in audit
return	Staff Returns → Admin Cancel	Stocks and sales are unchanged; records contain
return	at the note<img src=x onerror=alert(1)>	The admin page shows as text, does not work
Product Add	Add product together on two phones	Both are successful, separate codes
category	Men'sCategory, product, image	Edit/pictures open work on all pages
Exchange	10 pieces in 100 ৳ products, 5 returns, 5 new 120 ৳	Buy price = 110 ৳ (average); previous sales profit unchanged
Security	in the browser/Inventory/notification_config.jsonthe/Inventory/Doc/…php-old	403 or 404
Security	Attempting to delete audit logs	No feature at all
session	Just 25 minutes working on Item List, then Pos	Login is in
session	21 minutes without doing anything on any page	Goes to the original login page, the "Timeout" message
Report	Dashboard, Admin Control, Sales History, Category: Today's Sales & Profits	same number everywhere
Motion	10 minutes with the dashboard open	No heavy query on the server (slow-query log is empty)
Link	All Sidebar, Bottom Nave, ← Button	None goes to the homepage or 404
13. the meaning of the words
XSS	If someone writes code in the writing room, it goes to another's browser. How to prevent: "Escape" while showing, so that the code only shows as text.
csrf	Working secretly using your login from another site. Prevents secret tokens of each form.
Transaction	A few tasks together: either it will be all, or not one.
for update (row lock)	When one works with a stock of a product, the other waits for the other so that the two do not sell the last piece together.
migration	Written, sequential files to change the structure of the database, which runs only once.
staging	A copy of the live site, where the examination is done.
rollback	Return to the previous state if there is a problem with the new code.
Index	database tables; Quick search.
Average price based on weight	The average price of old and new pieces bought, according to the number of pieces.
Service / Repository / Controller	Code division: Rules, databases, and requests are handled in separate files.
This report is on 24 September 2026D:\21142\AccountsRead the folder code. Changing the code can cause the line number to be removed; Find the name of the id and file.
