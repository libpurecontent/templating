<?php

# Class providing templating pre-processing methods
# Version 0.9.2
class templating
{
	# Function to add placeholder surrounds to a raw HTML page
	public static function addPlaceholders ($replacements, $inputFile, $outputFile = false, &$error = false)
	{
		# Get the file contents
		$string = file_get_contents ($inputFile);
		
		# Do the replacements
		$i = 0;
		$delimiter = '@';
		foreach ($replacements as $fragment => $replacement) {
			$i++;
			$findRegexp = $delimiter . addcslashes ($fragment, $delimiter) . $delimiter . 'DsuU';	// Modifiers as listed at http://php.net/reference.pcre.pattern.modifiers
			$totalMatches = preg_match_all ($findRegexp, $string);
			if ($totalMatches != 1) {
				$error = "Fragment {$i} could not be found (once only) in {$inputFile} - it was found ($totalMatches) time(s).";
				return false;
			}
			$string = preg_replace ($findRegexp, $replacement, $string);	// %1$s is a repeated argument
		}
		
		# If no output file specified, overwrite the input file
		if (!$outputFile) {
			$outputFile = $inputFile;
		}
		
		# Save the file
		file_put_contents ($outputFile, $string);
		
		# Return success
		return true;
	}
	
	
	# Function to split up files into parts
	public static function splitUpFiles ($parts, $inputFile, &$error = '')
	{
		# Get the file contents
		$string = file_get_contents ($inputFile);
		
		# Do the extractions
		$i = 0;
		$delimiter = '@';
		foreach ($parts as $newFile => $findRegexp) {
			$i++;
			$findRegexp = $delimiter . addcslashes ($findRegexp, $delimiter) . $delimiter . 'DsuU';
			$totalMatches = preg_match_all ($findRegexp, $string, $matches);
			if ($totalMatches != 1) {
				$error = "The fragment for {$newFile} could not be found (once only) in {$inputFile} - it was found ($totalMatches) time(s).";
				return false;
			}
			
			# Extract the string from the matches
			$extractedString = $matches[0][0];
			
			# Create the file
			file_put_contents ($newFile, $extractedString);
		}
		
		# Return success
		return true;
	}
	
	
	# Function to convert the designer's raw HTML to a templatised HTML page; this is a preprocessor which enables a template to be dropped in from a designer without making changes first
	public static function convertDesignerHtmlToTemplate ($page, &$styleDirectory, &$replacedPlaceholders, $fallbackStyleDirectory = false)
	{
		# Determine the location
		$path = $styleDirectory . $page;
		$file = $_SERVER['DOCUMENT_ROOT'] . $path;
		
		# If the file does not exist, fall back to the default (which will exist, as it is part of the repository)
		if ($fallbackStyleDirectory) {
			if (!is_readable ($file)) {
				$styleDirectory = $fallbackStyleDirectory;
				$path = $styleDirectory . $page;
				$file = $_SERVER['DOCUMENT_ROOT'] . $path;
			}
		}
		
		# Load the file
		$html = file_get_contents ($file);
		
		# Chop trailing directory index; e.g. in the HTML "/path/index.html" becomes "/path/"
		$html = self::htmlCleanChopDirectoryIndex ($html);
		
		# Determine the path prefix that needs to be inserted
		$path = dirname ($path . '.bogus') . '/';	// .bogus ensures that dirname doesn't convert "/foo/bar" (which should not be supplied anyway) to /foo
		$delimiter = '@';
		$prefix = preg_replace ($delimiter . '^' . addcslashes ($styleDirectory, $delimiter) . $delimiter, '', $path);	// i.e. replace /style/default/ with /, leaving e.g. /contacts/
		
		# Make HTML paths absolute; e.g. "css/" becomes "/css/"
		$html = self::htmlCleanPathsAbsolute ($html, $prefix);
		
		# Replace templated sections with placeholders
		$html = self::commentsToPlaceholders ($html, $replacedPlaceholders);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to chop directory indexes from links
	private static function htmlCleanChopDirectoryIndex ($html)
	{
		# Define directory index filename(s)
		$supported = array ('index.html', );	// More can be added if found necessary, e.g. index.php
		$supportedDirectoryIndexesRegexp = implode ('|', $supported);
		
		# HTML href links
		$html = preg_replace ('@(\s+)(href)="(' . $supportedDirectoryIndexesRegexp . ')"@', '$1$2="./"', $html);			// Special case: href="index.html" becomes href="./"
		$html = preg_replace ('@(\s+)(href)="([^"]*)/(' . $supportedDirectoryIndexesRegexp . ')"@', '$1$2="$3/"', $html);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to rewrite HTML paths to be absolute
	public static function htmlCleanPathsAbsolute ($html, $prefix = '/')
	{
		# Extract all URL references or return HTML as-is)
		#!# No support for single-quoted (var='text') attributes quotes yet
		#!# Will currently catch href="path"/src="path" appearing within the HTML as plain text
		if (!preg_match_all ('@\s+(href|src)="([^"]+)"(\s|/>|>)@', $html, $pathsOriginal, PREG_SET_ORDER)) {return $html;}
		
		# Work through each path to determine the replacement path for each match
		$paths = array ();
		foreach ($pathsOriginal as $i => $match) {
			$paths[$i] = $match[2];		// By default, start with unamended original
			
			# Full URLs should be left unchanged
			if (preg_match ('|^https?://.+$|', $paths[$i])) {continue;}
			
			# Absolute URLs should be left unchanged
			if (preg_match ('|^/.+$|', $paths[$i])) {continue;}
			
			# Pure anchors should be left changed
			if (preg_match ('|^#.*$|', $paths[$i])) {continue;}
			
			# Current-directory only URLs ( ./ or ./something ) should be substituted with the absolute equivalent
			if ($paths[$i] == '.') {$paths[$i] = './';}	// Normalise
			if (preg_match ('|^\./(.*)$|', $paths[$i], $matches)) {
				$paths[$i] = $prefix . $matches[1];
				continue;
			}
			
			# Directory-traversal URLs - chop prefix for each, i.e. ../contacts/ => /prefix/../contacts/ => /contacts/
			if ($paths[$i] == '..') {$paths[$i] = '../';}	// Normalise
			if (preg_match ('|^\.\./(.*)$|', $paths[$i], $matches)) {
				$newPrefix = $prefix;
				while (preg_match ('|^\.\./(.*)$|', $paths[$i], $matches)) {
					if (strlen ($newPrefix)) {	// Never traverse higher than / - if HTML of ../../ should have been ../ then treat it as such
						$newPrefix = str_replace ('\\', '/', dirname ($newPrefix));	// Chop last component
					}
					$paths[$i] = $newPrefix . $matches[1];
				}
				continue;
			}
			
			# Prefix remainder, which are "from here" paths, e.g. "path/to" becomes "/prefix/path/to"
			$paths[$i] = $prefix . $paths[$i];
			
			#!# Not yet baseUrl -compliant
		}
		
		# Construct the find/replace entry; $match[1] is href/src; $match[2] is the original path
		$replacements = array ();
		foreach ($pathsOriginal as $i => $match) {
			$find    = sprintf (' %s="%s"', $match[1], $match[2]);
			$replace = sprintf (' %s="%s"', $match[1], $paths[$i]);
			$replacements[$find] = $replace;
		}
		
		# Perform replacements
		$html = strtr ($html, $replacements);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to insert placeholders, by replacing comments in the HTML where the placeholders go; the aim here is that a designer can supply code with both sample HTML and the placeholders in
	# This looks for "<!-- {$placeholdername} --> then lines of HTML here, then <!-- {/$placeholdername} -->"
	public static function commentsToPlaceholders ($html, &$replacedPlaceholders = array ())
	{
		# Cache matched placeholder comments; note \1 is a backreference to ensure the opening and closing tags match, and the s modifier enables multiple-line matches
		$regexp = '|' . '<!--\s+\{\$([^}]+)\}\s+-->(.*)<!--\s+/\{\$\1\}\s+-->' . '|sU';		// Ungreedy used to support multiple replacements of same placeholder
		if (preg_match_all ($regexp, $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$replacedPlaceholders[$match[1]] = $match[2];		// placeholdername => html
			}
		}
		
		# Do the replacement of placeholder comments with actual placeholders
		$html = preg_replace ($regexp, '{\$\1}', $html);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to push replacements into the template
	public static function doTemplateSubstitution ($templateHtml, $replacements, $styleDirectory = false)
	{
		# Resolve includes, enabled if a style directory is specified
		#!# Currently does not support recursive includes
		#!# Currently supports only files specified relative from the style directory
		if ($styleDirectory) {
			$includes = array ();
			preg_match_all ("/{include file='([^']+)'}/", $templateHtml, $matches, PREG_SET_ORDER);
			if ($matches) {
				foreach ($matches as $match) {
					$placeholder = $match[0];
					$filename = $match[1];
					$file = $_SERVER['DOCUMENT_ROOT'] . $styleDirectory . '/' . $filename;
					if (file_exists ($file)) {
						$includedTemplate = file_get_contents ($file);
						$templateHtml = str_replace ($placeholder, $includedTemplate, $templateHtml);
					}
				}
			}
		}
		
		# Convert to Smarty-format placeholders
		$substitutions = array ();
		foreach ($replacements as $find => $replace) {
			$find = '{$' . $find . '}';
			$substitutions[$find] = $replace;
		}
		
		# Perform substitutions
		$html = strtr ($templateHtml, $substitutions);
		
		# Return the HTML
		return $html;
	}
}

?>
