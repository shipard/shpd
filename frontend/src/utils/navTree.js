// Čisté helpery nad navigačním stromem (/_ui/navigation).
//
// Tvar uzlu: leaf má `type` ('viewer' | 'table' | 'panel' | …), skupina/sekce
// `type` nemá a nese `children`. Root úroveň mixuje sekce a root-level leafy
// (`_top`, dashboard, chat).

/**
 * Rekurzivně sebere všechny klikatelné leaves ze stromu navigace
 * v depth-first pořadí. Skupiny (bez `type`, jen s `children`) se
 * vynechají; jejich children se ploše zařadí do výsledku.
 */
export function flattenLeaves(tree) {
  const leaves = [];
  for (const node of tree) {
    if (node.type) {
      leaves.push(node);
    } else if (node.children) {
      leaves.push(...flattenLeaves(node.children));
    }
  }
  return leaves;
}

/** Rekurzivní hledání leafu dle id ve stromu navigace (sekce/skupiny). */
export function findLeafById(nodes, id) {
  if (!Array.isArray(nodes)) return null;
  for (const node of nodes) {
    if (node?.id === id && node?.type) return node;
    const found = findLeafById(node?.children, id);
    if (found) return found;
  }
  return null;
}

/**
 * Id root uzlu-sekce (uzel bez `type`, s `children`), pod nímž leaf
 * s daným id leží — libovolně hluboko (i v pod-skupině sekce).
 * Leaf na root úrovni (`_top`, dashboard, chat) → null; nenalezený → null.
 */
export function findRootSectionId(tree, leafId) {
  if (!Array.isArray(tree) || leafId == null) return null;
  for (const node of tree) {
    if (node?.type) continue; // root-level leaf — žádná sekce
    if (node?.children && findLeafById(node.children, leafId)) {
      return node.id;
    }
  }
  return null;
}
