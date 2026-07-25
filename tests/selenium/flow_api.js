class FlowApi {
	async reply( msg, uuid, apiClient ) {
		await apiClient.request( {
			action: 'flow',
			submodule: 'reply',
			page: `Topic:${ uuid }`,
			repreplyTo: uuid,
			repcontent: msg,
			repformat: 'wikitext',
			token: await apiClient.getEditToken()
		} );
	}

	async editTopicSummary( msg, uuid, apiClient ) {
		const res = await apiClient.request( {
			action: 'flow',
			submodule: 'view-topic-summary',
			page: `Topic:${ uuid }`,
			vtsformat: 'wikitext'
		} );
		const prevRev =
      res.flow[ 'view-topic-summary' ].result.topicsummary.revision.revisionId;

		await apiClient.request( {
			action: 'flow',
			submodule: 'edit-topic-summary',
			page: `Topic:${ uuid }`,
			// eslint-disable-next-line camelcase
			etsprev_revision: prevRev,
			etssummary: msg,
			etsformat: 'wikitext',
			token: await apiClient.getEditToken()
		} );
	}
}

export default new FlowApi();
