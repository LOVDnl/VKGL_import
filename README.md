# VKGL import

Every three months, the Dutch genome diagnostic laboratories release their new data.
This script will take their export file, normalize the variants, regroup the variants, and import it into an LOVD3 instance.
If previous records are found, they will be updated.

## Invoking the script

```
process_VKGL_data.php file_to_import.tsv [-y]
```
Note that the script is interactive.
The first time that it will run, it will ask you all the information it needs to process the data.
Settings are stored in a `settings.ini` file.
Only when settings have been provided before, the `-y` flag can be used.
Passing the `-y` flag will accept all previous settings.
