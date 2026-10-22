-- Votes refer to their sanctions by ID instead of the Flow topic.
ALTER TABLE /*_*/sanctions_vote
    ADD stv_sanction INT unsigned DEFAULT NULL AFTER stv_id,
    MODIFY stv_topic binary(11) DEFAULT NULL,
    ADD KEY stv_sanction_user (stv_sanction,stv_user);

UPDATE /*_*/sanctions_vote
    JOIN /*_*/sanctions ON st_topic = stv_topic
    SET stv_sanction = st_id;
