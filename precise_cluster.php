<?PHP

// error_reporting(E_ERROR|E_CORE_ERROR|E_COMPILE_ERROR); // E_ALL|
error_reporting(E_ALL);
ini_set('display_errors', 'On');
ini_set('memory_limit','1500M');
set_time_limit ( 60 * 10 ) ; // Seconds

require_once ( __DIR__ . '/magnustools/common.php' ) ;
require_once ( __DIR__ . '/magnustools/wikidata.php' ) ;
require_once ( __DIR__ . '/lib/wikidata_claims.php' ) ;
require_once ( __DIR__ . '/lib/article_model.php' ) ;
require_once ( __DIR__ . '/lib/cluster.php' ) ;
require_once ( __DIR__ . '/lib/clustering.php' ) ;
require_once ( __DIR__ . '/lib/qs_commands.php' ) ;
require_once ( __DIR__ . '/lib/author_data.php' ) ;
require_once ( __DIR__ . '/lib/name_model.php' ) ;
require_once ( __DIR__ . '/lib/display_code.php' ) ;

$action = get_request ( 'action' , '' ) ;
$name = trim ( str_replace ( '_' , ' ' , get_request ( 'name' , '' ) ) ) ;
$fuzzy = get_request ( 'fuzzy' , 0 ) * 1 ;
$fuzzy_checked = $fuzzy ? 'checked' : '' ;
$filter = get_request ( 'filter', '' ) ;
$filter_authors = get_request ( 'filter_authors', '') ;
$filter_authors_checked = $filter_authors ? 'checked' : '' ;
$filter_authors_checked = $filter_authors ? 'checked' : '' ;
$article_limit = get_request ( 'limit', '' ) ;
if ($article_limit == '' ) $article_limit = 500 ;
$limit_options = [10, 50, 200, 500] ;

print get_common_header ( '' , 'Author Disambiguator' ) ;

// Publications
$nm = new NameModel($name);
$names = $nm->default_search_strings();
if ( $fuzzy ) {
	$names = $nm->fuzzy_search_strings();
}

if ( $action == 'add' ) {
	print "<form method='post' class='form' action='https://tools.wmflabs.org/quickstatements/api.php'>" ;
	print "<input type='hidden' name='action' value='import' />" ;
	print "<input type='hidden' name='temporary' value='1' />" ;
	print "<input type='hidden' name='openpage' value='1' />" ;
	$orcid_author = trim ( get_request ( 'orcid_author' , '' ) ) ;
	$viaf_author = trim ( get_request ( 'viaf_author' , '' ) ) ;
	$author_match = trim ( get_request ( 'author_match' , '' ) ) ;
	$author_q = trim ( get_request ( 'q_author' , '' ) ) ;
	if ( $author_q == '' ) $author_q = $author_match ;
	$papers = get_request ( 'papers' , array() ) ;

	if ( $author_match == 'new' ) {
		print "<br/>Quickstatements V1 commands for creating new author item:" ;
		$commands = new_author_qs_commands ( $name, $orcid_author, $viaf_author ) ;
		print "<textarea name='data' rows=5>" . implode("\n",$commands) . "</textarea>" ;
		print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' /><br/>" ;
		print "Run these and then use the resulting author item ID (Qxx) in further work." ;
		print "</form>" ;
		exit ( 0 ) ;
	}
	if ( $author_q == '' ) {
		print "Sorry, can't find author" ;
		exit ( 0 ) ;
	}

	$commands = replace_authors_qs_commands ( $papers, $names, $author_q ) ;

	print "Quickstatements V1 commands for replacing author name strings with author item:" ;
	print "<textarea name='data' rows=20>" . implode("\n",$commands) . "</textarea>" ;
	print "<input type='submit' class='btn btn-primary' name='qs' value='Send to Quickstatements' />" ;
	print "</form>" ;
	
	exit ( 0 ) ;
}

print "<form method='get' class='form form-inline'>
Author name: 
<input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' />
<label><input type='checkbox' name='fuzzy' value='1' $fuzzy_checked /> Fuzzy match</label>
<div style='margin:20px'><input type='submit' class='btn btn-primary' name='doit' value='Look for author' /></div>
Limit: <select name='limit'>" ;
foreach ($limit_options AS $limit_option) {
	print "<option value='$limit_option'" ;
	if ($article_limit == $limit_option) print ' selected' ;
	print ">$limit_option</option>" ;
}
print "</select><br />
<div style='font-size:9pt'>Additional SPARQL filters separated by semicolons (eg. for papers on Zika virus, enter wdt:P921 wd:Q202864):
<input style='font-size:9pt' size='40' name='filter' value='" . escape_attribute($filter) . "' type='text' placeholder='wdt:PXXX wd:QYYYYY; wdt:PXX2 wd:QYY2 '/></div>
<div style='font-size:9pt'><input type='checkbox' name='filter_authors' value='1' $filter_authors_checked /> Filter potential authors as well?</div><br/>
</form>" ;

if ( $name == '' ) {
	print_footer() ;
	exit ( 0 ) ;
}


$author_names_strings = '"' . implode ( '" "' , $names ) . '"' ;

$languages_to_search = ['en', 'de', 'fr', 'es', 'nl'] ;
$names_with_langs = array();
foreach($languages_to_search AS $lang) {
	foreach($names AS $name_entry) {
		$names_with_langs[] = '"' . $name_entry . '"@' . $lang ;
	}
}
$names_strings = implode ( ' ' , $names_with_langs ) ;
$filter_in_context = "; $filter . ";
$sparql = "SELECT ?q { VALUES ?name { $author_names_strings } . ?q wdt:P2093 ?name $filter_in_context } LIMIT $article_limit" ;
#print $sparql ;
$items_papers = getSPARQLitems ( $sparql ) ;
$limit_reached = (count($items_papers) == $article_limit) ;
$items_papers = array_unique( $items_papers );

// Potential authors
$author_filter = $filter_authors ? "?article wdt:P50 ?q $filter_in_context" : '' ;
$items_authors = array() ;
$sparql = "SELECT DISTINCT ?q { VALUES ?name { $names_strings } . ?q (rdfs:label|skos:altLabel) ?name ; wdt:P31 wd:Q5 . $author_filter }" ;
#print $sparql ;
$items_individual_authors = getSPARQLitems ( $sparql ) ;

if (strlen($nm->last_name) < 4) {
	$items_collective_authors = []; # Otherwise may time out
} else {
	$sparql = "SELECT DISTINCT ?q { VALUES ?name { $names_strings } . ?q (rdfs:label|skos:altLabel) ?name ; wdt:P31/wdt:P279* wd:Q16334295 . $author_filter }" ;
#print $sparql ;
	$items_collective_authors = getSPARQLitems ( $sparql ) ;
}
$sparql = "SELECT DISTINCT ?q { VALUES ?name { $author_names_strings } . ?paper p:P50 ?statement . ?statement ps:P50 ?q ; pq:P1932 ?name . $author_filter }" ;
#print $sparql ;
$items_stated_as_authors = getSPARQLitems ( $sparql ) ;

$items_authors = array_unique( array_merge( $items_individual_authors, $items_collective_authors, $items_stated_as_authors ) ) ;

// Load items
$wil = new WikidataItemList ;
$to_load = array() ;
foreach ( $items_authors AS $q ) $to_load[] = $q ;
$wil->loadItems ( $to_load ) ;

$potential_author_data = AuthorData::authorDataFromItems( $items_authors, $wil ) ;
$to_load = array() ;
foreach ($potential_author_data AS $author_data) {
	foreach ($author_data->employer_qids as $q) $to_load[] = $q ;
}
$to_load = array_unique($to_load);
$wil->loadItems ( $to_load ) ;

print "<form method='post' class='form' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='fuzzy' value='$fuzzy' />
<input type='hidden' name='name' value='" . escape_attribute($name) . "' />" ;

$to_load = array() ;
$article_items = generate_article_entries( $items_papers );
foreach ( $article_items AS $article ) {
	foreach ( $article->authors AS $auth) $to_load[] = $auth ;
	foreach ( $article->published_in AS $pub ) $to_load[] = $pub ;
	foreach ( $article->topics AS $topic ) $to_load[] = $topic ;
}
$to_load = array_unique( $to_load );
$wil->loadItems ( $to_load ) ;

$clusters = array();
foreach ($article_items as $article) {
	foreach ( $article->author_names AS $num => $a ) {
		if ( in_array ( $a , $names ) ) {
			$neighbor_names = neighboring_author_strings($article, $num);
			$article_num = $article->q . ':' . $num ;
			if (! isset($clusters[$neighbor_names])) {
				$clusters[$neighbor_names] = array();
			}
			$clusters[$neighbor_names][] = $article_num;
		}
	}
}

uasort ( $clusters, function ($a, $b) {
		return count($b) - count($a) ;
	}
);

foreach ($clusters as $key => $cluster) {
	if (count($cluster) == 1) {
		if (! isset($clusters['Misc']) ) {
			$clusters['Misc'] = array();
		}
		$clusters['Misc'][] = $cluster[0];
		unset($clusters[$key]);
	}
}

foreach (array_keys($clusters) as $key) {
	usort( $clusters[$key], function ($a, $b ) use ($article_items) {
		$q_a = substr($a, 0, strpos($a, ':'));
		$q_b = substr($b, 0, strpos($a, ':'));
		$article_a = $article_items[$q_a] ;
		$article_b = $article_items[$q_b] ;
		return WikidataArticleEntry::dateCompare($article_a, $article_b);
	} ) ;
}

// Publications
$name_counter = array() ;
print "<h2>Potential publications</h2>" ;
print "<p>" . count($article_items) . " publications found</p>" ;
if ( $limit_reached ) {
	print "<div><b>Warning:</b> limit reached; process these papers and then reload to see if there are more for this author</div>" ;
}
print "<div style='font-size:9pt'><a href='index.php?name=$name&fuzzy=$fuzzy&limit=$article_limit'> Click here for rougher clustering.</a> </div> " ;

$is_first_group = true ;
foreach ( $clusters AS $cluster_name => $cluster ) {
	print "<div class='group'>" ;
	print "<h3>$cluster_name</h3>" ;
?>
<div>
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",true);return false'>Check all</a> | 
<a href='#' onclick='$($(this).parents("div.group")).find("input[type=checkbox]").prop("checked",false);return false'>Uncheck all</a>
</div>
<?PHP
	print "<table class='table table-striped table-condensed'>" ;
	print "<tbody>" ;
	print "<tr><th>Title</th>" ;
	print "<th>Authors (<span style='color:green'>identified</span>)</th>" ;
	print "<th>Published In</th><th>Identifier(s)</th>" ;
	print "<th>Topic</th><th>Published Date</th><th>Match?</th></tr>" ;
	foreach ( $cluster AS $article_author ) {
		$sep_pos = strpos($article_author, ':');
		$q = substr($article_author, 0, $sep_pos);
		$auth_num = substr($article_author, $sep_pos + 1) ;
		$article = $article_items[$q];

		$formatted_authors = array();
		foreach ( $article->author_names AS $num => $a ) {
			if ( $num == $auth_num ) {
				$formatted_authors[$num] = "[$num]" .
			"<input type='checkbox' name='papers[$q:$num]' value='$q:$num' " .
			($is_first_group?'checked':'') . " /><b>$a</b>" ;
			} else {
				$formatted_authors[$num] = "[$num]<a href='?fuzzy=$fuzzy&limit=$article_limit&name=" . urlencode($a) . "'>$a</a>" ;
				$name_counter[$a] = isset($name_counter[$a]) ? $name_counter[$a]+1 : 1 ;
			}
		}
		
		foreach ( $article->authors AS $num => $qt ) {
			$display_num = $num ;
			if (isset($formatted_authors[$num])) {
				$display_num = "$num-$qt";
			}
			$i2 = $wil->getItem ( $qt ) ;
			if ( !isset($i2) ) continue ;
			$label = $i2->getLabel() ;
			$formatted_authors[$display_num] = "[$display_num]<a href='author_item.php?id=$qt' target='_blank' style='color:green'>$label</a>" ;
		}
		ksort($formatted_authors);
		$authors_list = implode ( ', ' , compress_display_list($formatted_authors, [$auth_num], 20, 10, 2)) ;

		$published_in = array() ;
		foreach ( $article->published_in AS $qt ) {
			$i2 = $wil->getItem ( $qt ) ;
			if ( isset($i2) ) $published_in[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'black') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/venue/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
		}
		$published_in_list = implode ( ', ', $published_in ) ;
	
		print "<tr>" ;
		print "<td style='width:20%;font-size:10pt'>" . wikidata_link($q, $article->title, '') . "</td>" ;
		print "<td style='width:50%;font-size:9pt'>$authors_list</td>" ;
		print "<td style='font-size:9pt'>$published_in_list</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( $article->doi != '' ) {
			print "DOI: <a target='_blank' href='https://doi.org/$article->doi'>$article->doi</a>" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->doi ) . "'>ORCID</a>]<br/>" ;
		}
		if ( $article->pmid != '' ) {
			print "PubMed: <a target='_blank' href='https://www.ncbi.nlm.nih.gov/pubmed/?term=$article->pmid'>$article->pmid</a>" ;
			print "&nbsp;[<a href='" . getORCIDurl ( $article->pmid ) . "'>ORCID</a>]<br/>" ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		if ( count($article->topics) > 0 ) {
			$topics = [] ;
			foreach ( $article->topics AS $qt ) {
				$i2 = $wil->getItem($qt) ;
				if ( !isset($i2) ) continue ;
				$topics[] = wikidata_link($i2->getQ(), $i2->getLabel(), 'brown') . "&nbsp;[<a href='https://tools.wmflabs.org/scholia/topic/" . $i2->getQ() . "/missing' target='_blank'>missing</a>]" ;
			}
			print implode ( '; ' , $topics ) ;
		}
		print "</td>" ;
                print "<td style='font-size:9pt'>" ;
		print $article->formattedPublicationDate () ;
		print "</td><td style='font-size:10pt'>" ;

		print "</td>" ;
		print "</tr>" ;
	}
	print "</tbody></table></div>" ;
	$is_first_group = false ;
}

// Potential authors
#print "<pre>" ; print_r ( $items_authors ) ; print "</pre>" ;
print "<h2>Potential author items</h2>" ;
print "<table class='table table-striped table-condensed'>" ;
print "<tbody>" ;
print "<tr><th></th><th>Name</th><th>Description</th><th>Authored items</th>" ;
print "<th>Identifiers</th><th>Employer(s)</th></tr>" ;
foreach ( $potential_author_data AS $q => $author_data ) {
	$i = $wil->getItem ( $q ) ;
	if ( !isset($i) ) continue ;
	print "<tr>" ;
	print "<td><input type='radio' name='author_match' value='$q' /></td>" ;
	print "<td><a href='author_item.php?id=" . $i->getQ() . "' target='_blank' style='color:green'>" . $i->getLabel() . "</a></td>" ;
	print "<td>" . $i->getDesc() . "</td>" ;
	print "<td>$author_data->article_count</td>" ;
	print "<td>" ;
	if ( $author_data->orcid != '' ) {
		print "ORCID: <a target='_blank' href='https://orcid.org/$author_data->orcid'>$author_data->orcid</a><br/>" ;
	}
	if ( $author_data->isni != '' ) {
		$isni = preg_replace('/\s+/', '', $author_data->isni) ;
		print "ISNI: <a target='_blank' href='http://isni.org/$isni'>$author_data->isni</a><br/>" ;
	}
	if ( $author_data->rsrchrid != '' ) {
		print "Researcher ID: <a target='_blank' href='https://www.researcherid.com/rid/$author_data->rsrchrid'>$author_data->rsrchrid</a><br/>" ;
	}
	if ( $author_data->viaf != '' ) {
		print "VIAF ID: <a target='_blank' href='https://viaf.org/viaf/$author_data->viaf'>$author_data->viaf</a><br/>" ;
	}
	print "</td><td>" ;
	foreach ( $author_data->employer_qids AS $emp_qid ) {
		$emp_item = $wil->getItem ( $emp_qid ) ;
		if ( !isset($emp_item) ) continue ;
		print wikidata_link($emp_qid, $emp_item->getLabel(), '') . "<br/>" ;
	}
	print "</td></tr>" ;
}
print "<tr><td><input type='radio' name='author_match' value='manual' checked /></td><td><input type='text' name='q_author' placeholder='Qxxx' /></td><td colspan='4'>Other Q number of this author</td></tr>" ;
print "</tbody></table>" ;

print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to link works to author' class='btn btn-primary' /></div>" ;
print "</form>" ;

print "<h2>New Author Item</h2>" ;
print '(if the author you are looking for is not listed above and otherwise not yet in Wikidata)';

print "<form method='post' class='form form-inline' target='_blank' action='?'>
<input type='hidden' name='action' value='add' />
<input type='hidden' name='author_match' value='new'/>
<div>Author name: <input name='name' value='" . escape_attribute($name) . "' type='text' placeholder='First Last' /></div>";
print "<div><a href='" . getORCIDurl($name) . "' target='_blank'>Check ORCID for $name</a> | Author has ORCID ID: <input type='text' name='orcid_author' placeholder='xxxx-xxxx-xxxx-xxxx' /></div>" ;
print "<div><a href='https://viaf.org/viaf/search?query=local.personalNames%20all%20%22$name' target='_blank'>Check VIAF for $name</a> | Author has VIAF ID: <input type='text' name='viaf_author' placeholder='xxxxxxxxxxxxxxxxxxxx' /></div>" ;
print "<div style='margin:20px'><input type='submit' name='doit' value='Quickstatements to create author item' class='btn btn-primary' /></div>" ;
print "</form>" ;
print '<div>After creating the new author item, enter the Wikidata ID in the "Other Q number of this author" field above to link to their works.</div>' ;

arsort ( $name_counter , SORT_NUMERIC ) ;
print "<h2>Common names in these papers</h2>" ;
print "<ul>" ;
foreach ( $name_counter AS $a => $cnt ) {
	if ( $cnt == 1 ) break ;
	print "<li><a href='?fuzzy=$fuzzy&limit=$article_limit&name=" . urlencode($a) . "'>$a</a> ($cnt&times;)</li>" ;
}
print "</ul>" ;

print_footer() ;

?>