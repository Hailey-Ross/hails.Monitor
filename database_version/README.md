# Database Version
*This version of the script is provided as is.*
  
---
You will need:  
- Webserver  
- Database  
- Basic Database knowledge  

Do NOT:  
- Request Help  
- host your `config.php` in a non-secure directory
- Share your API-Key
---
### General setup
**Note:**
*The files will be follow a similar layout to the repository you are viewing.*  
*Everything inside of `PHP` will be the root of your webserver.*  
- Download or copy/paste the contents of `PHP` to your computer for editing.

- Update the URL's to point to your webserver, this is where you are hosting the php file `av.php`  
IE: `string server_url = "https://mysite.com/av.php";`  
Files to edit: `hails.Lookup.lsl`, `hails.Monitor.lsl`  
  
- Next create, or make-up an API Key that must match in all locations and/or scripts.  
IE: `string API_KEY = "SECRET-API-KEY";`  
Files to edit: `config.php`, `hails.Lookup.lsl`, `hails.Monitor.lsl`  

- Do NOT forget to place the `.htaccess` file in the same directory as your `config.php` file.
- There is an **optional** `.htaccess` file added to remove directory listing with `av.php` if no index is in the same folder.  
