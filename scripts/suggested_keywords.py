# -*- coding: utf-8 -*-

from sys import argv

from furl import furl
from rollbar import report_message
from simplejson import dumps, JSONDecodeError, loads

from utilities import get_responses


def get_suggested_keywords(original_keywords):
    suggested_keywords = []
    original_keywords = [
        original_keyword.lower() for original_keyword in original_keywords
    ]
    for response in get_responses([
        furl(
            'http://completion.amazon.com/search/complete'
        ).add({
            'mkt': '1',
            'q': original_keyword,
            'search-alias': 'digital-text',
            'xcat': '2',
        }).url
        for original_keyword in original_keywords
    ]):
        if not response:
            continue
        contents = ''
        try:
            contents = loads(response.text)
        except JSONDecodeError:
            pass
        if not contents:
            continue
        for keyword in contents[1]:
            if ' ' not in keyword:
                continue
            words = keyword.lower().split(' ')
            if len([word for word in words if word in original_keywords]) >= 2:
                if keyword not in suggested_keywords:
                    suggested_keywords.append(keyword)
    return sorted(suggested_keywords)

if __name__ == '__main__':
    suggested_keywords = get_suggested_keywords(argv[1].split(','))
    if not suggested_keywords:
        report_message(
            'get_suggested_keywords()',
            extra_data={
                'original_keywords': argv[1],
            },
            level='critical',
        )
    print dumps(suggested_keywords)
