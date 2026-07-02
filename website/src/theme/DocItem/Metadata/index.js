import React from 'react';
import Metadata from '@theme-original/DocItem/Metadata';
import {useDoc} from '@docusaurus/plugin-content-docs/client';
import RuntimeBadge from '@site/src/components/RuntimeBadge';

export default function MetadataWrapper(props) {
  const {frontMatter} = useDoc();
  return (
    <>
      <Metadata {...props} />
      {frontMatter.runtimes && <RuntimeBadge runtimes={frontMatter.runtimes} />}
    </>
  );
}
