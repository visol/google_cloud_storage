#
# Table structure for table 'tx_google_cloud_storage_media'
#
CREATE TABLE tx_google_cloud_storage_media (
  public_id text,
  public_id_hash char(40) DEFAULT '' NOT NULL,
  filename text,
  filename_hash char(40) DEFAULT '' NOT NULL,
  sha1 char(40) DEFAULT '' NOT NULL,
  modification_date int(11) DEFAULT '0' NOT NULL,

  PRIMARY KEY (public_id_hash),
  KEY filename_hash (filename_hash),
);

#
# Table structure for table 'tx_google_cloud_storage_responsivebreakpoints'
#
CREATE TABLE tx_google_cloud_storage_responsivebreakpoints (
  public_id text,
  public_id_hash char(40) DEFAULT '' NOT NULL,
  options_hash char(40) DEFAULT '' NOT NULL,
  breakpoints text NOT NULL,

  PRIMARY KEY (public_id_hash, options_hash),
);

#
# Table structure for table 'tx_google_cloud_storage_processedresources'
#
CREATE TABLE tx_google_cloud_storage_processedresources (
  public_id text,
  public_id_hash char(40) DEFAULT '' NOT NULL,
  options_hash char(40) DEFAULT '' NOT NULL,
  breakpoints text NOT NULL,

  PRIMARY KEY (public_id_hash, options_hash),
);
