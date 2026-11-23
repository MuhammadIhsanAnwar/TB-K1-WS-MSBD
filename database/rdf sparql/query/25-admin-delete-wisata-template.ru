# Query DELETE dari Database3CatalogWriter::delete().
# Ganti {{IRI_SUBJEK}} dengan IRI resource yang sudah divalidasi.
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

DELETE {
  ?related ?predicate <{{IRI_SUBJEK}}> .
  <{{IRI_SUBJEK}}> ?outPredicate ?outObject .
  ?statement ?statementPredicate ?statementObject .
}
WHERE {
  OPTIONAL { ?related ?predicate <{{IRI_SUBJEK}}> }
  OPTIONAL { <{{IRI_SUBJEK}}> ?outPredicate ?outObject }
  OPTIONAL {
    ?statement rdf:type rdf:Statement ;
      rdf:subject|rdf:object <{{IRI_SUBJEK}}> ;
      ?statementPredicate ?statementObject
  }
}
