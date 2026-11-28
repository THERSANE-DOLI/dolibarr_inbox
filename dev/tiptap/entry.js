// Everything inbox uses from Tiptap, exposed as window.InboxTiptap (the adapter js/inbox-editor.js reads it).
// Only extensions whose HTML is understood by the mail software (inline styles, simple tables)
export { Editor, Node, Mark, Extension, mergeAttributes } from '@tiptap/core';
export { default as StarterKit } from '@tiptap/starter-kit';
export { default as Image } from '@tiptap/extension-image';
export { Placeholder } from '@tiptap/extensions';
export { TableKit, Table, TableRow, TableCell, TableHeader } from '@tiptap/extension-table';
export { TextStyle, Color, BackgroundColor } from '@tiptap/extension-text-style';
export { default as TextAlign } from '@tiptap/extension-text-align';
