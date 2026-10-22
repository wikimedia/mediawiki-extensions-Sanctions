-- Sanctions discussed with DiscussionTools have their own pages instead of Flow topics.
ALTER TABLE /*_*/sanctions
    MODIFY st_topic binary(11) DEFAULT NULL,
    ADD st_page INT unsigned DEFAULT NULL AFTER st_topic,
    ADD KEY st_page (st_page);
